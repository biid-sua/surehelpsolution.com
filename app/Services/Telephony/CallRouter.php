<?php

namespace App\Services\Telephony;

use App\Actions\Customers\MatchOrCreateCustomer;
use App\Models\BusinessHour;
use App\Models\Organization;
use App\Models\PhoneNumber;
use App\Models\VoiceCall;
use App\Services\Billing\Trials;
use App\Services\Business\BusinessHours;
use DOMElement;

/**
 * Where a call goes (D60, D61), as TwiML. A call to a business's line rings every available agent who
 * serves it at once; whoever answers first gets it. Nobody available or nobody answers: the
 * owner's transfer number if the line has one, then voicemail. Missed calls become call-back tasks.
 *
 * The AI receptionist (D108), where the business has it, answers instead: every call ("always"),
 * while the business is closed ("after_hours"), or whenever no person can ("backup").
 */
class CallRouter
{
    public function __construct(
        private readonly TelephonyProvider $provider,
        private readonly AgentPresence $presence,
        private readonly VoiceCalls $calls,
    ) {}

    /** @param array<string, mixed> $params the vendor's webhook fields */
    public function incoming(array $params): string
    {
        $line = PhoneNumber::withoutGlobalScopes()->active()->where('number', PhoneNumber::normalize((string) ($params['To'] ?? '')))->first();
        $sid = (string) ($params['CallSid'] ?? '');
        if (! $line || $sid === '') {
            return TwiML::make()->say('Sorry, this number is not in service.')->hangup()->xml();
        }
        $organization = Organization::query()->find($line->organization_id);
        if (! $organization) {
            return TwiML::make()->say('Sorry, this number is not in service.')->hangup()->xml();
        }

        $from = PhoneNumber::normalize((string) ($params['From'] ?? '')) ?? ((string) ($params['From'] ?? '') ?: null);
        $closed = $this->isClosed($organization);
        $call = VoiceCall::withoutGlobalScopes()->firstOrCreate(['provider_call_sid' => $sid], [
            'organization_id' => $organization->id,
            'phone_number_id' => $line->id,
            'provider' => $this->provider->name(),
            'direction' => 'inbound',
            'from_number' => $from,
            'to_number' => $line->number,
            'status' => VoiceCall::RINGING,
            'after_hours' => $closed,
            'started_at' => now(),
            'customer_id' => $from ? (app(MatchOrCreateCustomer::class)->lookup($organization, $from, null)['customer'] ?? null)?->id : null,
        ]);

        // The AI receptionist first, when it answers every call or the business is closed (D108).
        // A free trial past its included calls, or one that ended, goes straight to the business (D97).
        $aiMode = $this->aiMode($organization);
        if ($aiMode === 'always' || ($closed && in_array($aiMode, ['after_hours', 'backup'], true) && $line->after_hours !== 'agents')) {
            $call->forceFill(['offered_to' => []])->save();

            return app(AiReceptionist::class)->start($call, TwiML::make());
        }

        // After hours the line may skip the agents (D61); "agents" answers as during the day.
        $agents = ($closed && $line->after_hours !== 'agents') || ! app(Trials::class)->answersCalls($organization)
            ? collect() : $this->presence->availableFor($organization);
        $twiml = TwiML::make();
        if ($line->record_calls) {
            $twiml->say((string) config('telephony.disclosure'));
            if (config('telephony.transcribe')) {
                $twiml->startTranscription([
                    'statusCallbackUrl' => route('webhooks.voice.transcription', $call),
                    'track' => 'both_tracks',
                    'inboundTrackLabel' => 'caller',
                    'outboundTrackLabel' => 'agent',
                    'partialResults' => false,
                ]);
            }
        }

        if ($agents->isEmpty()) {
            $call->forceFill(['offered_to' => []])->save();
            if ($aiMode === 'backup') {
                return app(AiReceptionist::class)->start($call, $twiml);   // nobody free: the AI picks up (D108)
            }
            if ($closed && $line->after_hours === 'voicemail') {
                return $this->voicemail($call, $twiml)->xml();
            }

            return $this->fallback($call, $line, $twiml);
        }

        $call->forceFill(['offered_to' => $agents->pluck('id')->all()])->save();
        $caller = $call->customer?->fullName() ?: $call->fromDisplay();

        return $twiml->dial([
            'timeout' => (int) config('telephony.ring_seconds'),
            'answerOnBridge' => true,
            'action' => route('webhooks.voice.dial-finished', $call),
            'record' => $line->record_calls ? 'record-from-answer-dual' : null,
            'recordingStatusCallback' => $line->record_calls ? route('webhooks.voice.recording', $call) : null,
            'recordingStatusCallbackEvent' => $line->record_calls ? 'completed' : null,
        ], function (DOMElement $dial, TwiML $t) use ($agents, $call, $organization, $caller) {
            foreach ($agents as $agent) {
                $client = $t->into($dial, 'Client', null, [
                    'statusCallback' => route('webhooks.voice.agent-leg', $call),
                    'statusCallbackEvent' => 'answered completed',
                    'statusCallbackMethod' => 'POST',
                ]);
                $t->into($client, 'Identity', AgentPresence::identity($agent));
                foreach (['callId' => $call->ulid, 'business' => $organization->name, 'businessId' => $organization->ulid, 'caller' => $caller] as $name => $value) {
                    $t->into($client, 'Parameter', null, ['name' => $name, 'value' => mb_substr($value, 0, 100)]);
                }
            }
        })->xml();
    }

    /**
     * An agent's browser places a call it asked for first (POST agent/phone/dial). Only that call, to
     * that number, from that agent, and only once: the browser can't choose who to call (D61).
     *
     * @param  array<string, mixed>  $params
     */
    public function outgoing(array $params): string
    {
        $call = VoiceCall::withoutGlobalScopes()->where('ulid', (string) ($params['callId'] ?? ''))->first();
        $agentId = AgentPresence::userIdFrom((string) ($params['From'] ?? $params['Caller'] ?? ''));

        // Back to a caller waiting on hold (warm transfer cancelled, D62): only the agent who held them, once.
        if (($params['rejoin'] ?? '') === '1') {
            if (! $call || $agentId === null || $call->answered_by_user_id !== $agentId || $call->held_at === null || $call->status !== VoiceCall::IN_PROGRESS) {
                return TwiML::make()->say('The caller is no longer waiting.')->hangup()->xml();
            }
            $call->forceFill(['held_at' => null])->save();

            return $this->conference($call, TwiML::make(), start: true)->xml();
        }
        $line = $call?->phoneNumber;
        if (! $call || $call->direction !== 'outbound' || $agentId === null || $call->placed_by_user_id !== $agentId
            || ! str_starts_with($call->provider_call_sid, 'pending-') || $call->started_at->lt(now()->subMinutes(2)) || ! $line || $line->status !== PhoneNumber::STATUS_ACTIVE) {
            return TwiML::make()->say('This call could not be placed.')->hangup()->xml();
        }
        $call->forceFill(['provider_call_sid' => (string) ($params['CallSid'] ?? $call->provider_call_sid)])->save();

        return TwiML::make()->dial([
            'callerId' => $line->number,
            'timeout' => 30,
            'answerOnBridge' => true,
            'record' => $line->record_calls ? 'record-from-answer-dual' : null,
            'recordingStatusCallback' => $line->record_calls ? route('webhooks.voice.recording', $call) : null,
            'recordingStatusCallbackEvent' => $line->record_calls ? 'completed' : null,
        ], function (DOMElement $dial, TwiML $t) use ($call): void {
            $t->into($dial, 'Number', (string) $call->to_number, [
                'statusCallback' => route('webhooks.voice.callee', $call),
                'statusCallbackEvent' => 'answered completed',
                'statusCallbackMethod' => 'POST',
            ]);
        })->xml();
    }

    /**
     * The agents' phones stopped ringing or the agent hung up.
     *
     * @param  array<string, mixed>  $params
     */
    public function dialFinished(VoiceCall $call, array $params): string
    {
        $call->refresh();
        if ($call->held_at !== null && $call->status === VoiceCall::IN_PROGRESS) {
            return $this->hold($call); // on hold for a warm transfer: stay in the waiting room
        }
        if ($call->status === VoiceCall::TRANSFERRED) {
            return TwiML::make()->xml(); // the transfer instructions already took over
        }
        if (($params['DialCallStatus'] ?? '') === 'completed' || $call->answered_at !== null) {
            return TwiML::make()->hangup()->xml();
        }
        // Nobody picked up: the AI receptionist answers instead of the owner or voicemail (D108).
        $organization = Organization::query()->find($call->organization_id);
        $aiMode = $organization ? $this->aiMode($organization) : null;
        if ($aiMode === 'backup' || ($aiMode === 'after_hours' && $call->after_hours)) {
            return app(AiReceptionist::class)->start($call, TwiML::make());
        }

        return $this->fallback($call, $call->phoneNumber, TwiML::make());
    }

    /** Agent asked to send the caller to the owner (the call was redirected here). */
    public function transfer(VoiceCall $call): string
    {
        $line = $call->phoneNumber;
        if (! $line?->transfer_number) {
            return $this->voicemail($call, TwiML::make())->xml();
        }

        return $this->dialOwner($call, $line, TwiML::make()->say('Please hold while I transfer your call.'))->xml();
    }

    /** Warm transfer (D62): the caller waits in a conference room with music until someone joins. */
    public function hold(VoiceCall $call): string
    {
        return $this->conference($call, TwiML::make()->say('Please hold for a moment while I connect you.'), start: false)->xml();
    }

    /** Warm transfer (D62): the owner (moved here from the agent's call) joins the waiting caller. */
    public function join(VoiceCall $call): string
    {
        return $this->conference($call, TwiML::make(), start: true)->xml();
    }

    private function conference(VoiceCall $call, TwiML $twiml, bool $start): TwiML
    {
        return $twiml->dial([], function (DOMElement $dial, TwiML $t) use ($call, $start): void {
            $t->into($dial, 'Conference', $call->conferenceName(), [
                'startConferenceOnEnter' => $start,
                'endConferenceOnExit' => true,
                'beep' => false,
            ]);
        });
    }

    /** @param array<string, mixed> $params */
    public function ownerFinished(VoiceCall $call, array $params): string
    {
        if (($params['DialCallStatus'] ?? '') === 'completed') {
            $this->calls->ownerAnswered($call);

            return TwiML::make()->hangup()->xml();
        }

        return $this->voicemail($call, TwiML::make()->say('Sorry, they could not take your call.'))->xml();
    }

    public function voicemailSaved(): string
    {
        return TwiML::make()->say('Thank you. We will get back to you as soon as we can. Goodbye.')->hangup()->xml();
    }

    /** The caller asked the AI receptionist for a person (D108): put them through to the owner. */
    public function transferFromAi(VoiceCall $call, PhoneNumber $line, TwiML $twiml): string
    {
        return $this->dialOwner($call, $line, $twiml)->xml();
    }

    /** When the AI receptionist answers this business's calls, or null; never once a trial stopped answering (D97). */
    private function aiMode(Organization $organization): ?string
    {
        return app(Trials::class)->answersCalls($organization) ? app(AiReceptionist::class)->modeFor($organization) : null;
    }

    /** Closed now by the business's own hours, holidays and closures. A business with no hours set is never "closed". */
    private function isClosed(Organization $organization): bool
    {
        if (! BusinessHour::query()->forOrganization($organization)->exists()) {
            return false;
        }

        return ! app(BusinessHours::class)->status($organization)['open'];
    }

    private function fallback(VoiceCall $call, ?PhoneNumber $line, TwiML $twiml): string
    {
        $tried = ($call->transferred_to !== null);
        if ($line?->transfer_number && ! $tried) {
            return $this->dialOwner($call, $line, $twiml)->xml();
        }

        return $this->voicemail($call, $twiml)->xml();
    }

    private function dialOwner(VoiceCall $call, PhoneNumber $line, TwiML $twiml): TwiML
    {
        $call->forceFill(['transferred_to' => $line->transfer_number])->save();

        return $twiml->dial([
            'timeout' => 25,
            'callerId' => $line->number,
            'action' => route('webhooks.voice.owner-finished', $call),
            'record' => $line->record_calls ? 'record-from-answer-dual' : null,
            'recordingStatusCallback' => $line->record_calls ? route('webhooks.voice.recording', $call) : null,
            'recordingStatusCallbackEvent' => $line->record_calls ? 'completed' : null,
        ], function (DOMElement $dial, TwiML $t) use ($line): void {
            $t->into($dial, 'Number', $line->transfer_number);
        });
    }

    private function voicemail(VoiceCall $call, TwiML $twiml): TwiML
    {
        return $twiml->say((string) config('telephony.voicemail_prompt'))->record([
            'maxLength' => (int) config('telephony.voicemail_seconds'),
            'playBeep' => true,
            'action' => route('webhooks.voice.voicemail-saved', $call),
            'recordingStatusCallback' => route('webhooks.voice.recording', ['voiceCall' => $call, 'voicemail' => 1]),
            'recordingStatusCallbackEvent' => 'completed',
        ]);
    }
}

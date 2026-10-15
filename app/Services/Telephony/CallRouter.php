<?php

namespace App\Services\Telephony;

use App\Actions\Customers\MatchOrCreateCustomer;
use App\Models\Organization;
use App\Models\PhoneNumber;
use App\Models\VoiceCall;
use DOMElement;

/**
 * Where a call goes (D60), as TwiML. A call to a business's line rings every available agent who
 * serves it at once; whoever answers first gets it. Nobody available or nobody answers: the
 * owner's transfer number if the line has one, then voicemail. Missed calls become call-back tasks.
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
        $call = VoiceCall::withoutGlobalScopes()->firstOrCreate(['provider_call_sid' => $sid], [
            'organization_id' => $organization->id,
            'phone_number_id' => $line->id,
            'provider' => $this->provider->name(),
            'direction' => 'inbound',
            'from_number' => $from,
            'to_number' => $line->number,
            'status' => VoiceCall::RINGING,
            'started_at' => now(),
            'customer_id' => $from ? (app(MatchOrCreateCustomer::class)->lookup($organization, $from, null)['customer'] ?? null)?->id : null,
        ]);

        $agents = $this->presence->availableFor($organization);
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
     * The agents' phones stopped ringing or the agent hung up.
     *
     * @param  array<string, mixed>  $params
     */
    public function dialFinished(VoiceCall $call, array $params): string
    {
        $call->refresh();
        if ($call->status === VoiceCall::TRANSFERRED) {
            return TwiML::make()->xml(); // the transfer instructions already took over
        }
        if (($params['DialCallStatus'] ?? '') === 'completed' || $call->answered_at !== null) {
            return TwiML::make()->hangup()->xml();
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

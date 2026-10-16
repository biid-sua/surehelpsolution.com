<?php

namespace App\Services\Telephony;

use App\Actions\Customers\MatchOrCreateCustomer;
use App\Exceptions\TelephonyUnavailable;
use App\Jobs\FinishVoiceCall;
use App\Jobs\SummarizeVoiceCall;
use App\Models\Organization;
use App\Models\PhoneNumber;
use App\Models\User;
use App\Models\VoiceCall;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What happens to a call as it goes (D60): answered, ended, recorded, transcribed, transferred.
 * Webhooks and the demo softphone both go through here, so they behave the same.
 */
class VoiceCalls
{
    public function __construct(
        private readonly AgentPresence $presence,
        private readonly TelephonyProvider $provider,
        private readonly Audit $audit,
    ) {}

    /** The first agent to pick up gets the call; false when someone else already has it. */
    public function answer(VoiceCall $call, User $agent): bool
    {
        $now = now();
        $taken = VoiceCall::withoutGlobalScopes()->whereKey($call->id)->where('status', VoiceCall::RINGING)->update([
            'status' => VoiceCall::IN_PROGRESS,
            'answered_by_user_id' => $agent->id,
            'answered_at' => $now,
            'wait_seconds' => max(0, (int) $call->started_at->diffInSeconds($now)),
            'updated_at' => $now,
        ]);
        $call->refresh();
        if ($taken === 1) {
            $this->presence->set($agent, AgentPresence::BUSY);
        }

        return $taken === 1;
    }

    /** The agent's side of the call ended (hung up, or the call moved on). */
    public function agentLeft(VoiceCall $call, User $agent): void
    {
        if ($call->answered_by_user_id === $agent->id && $agent->phone_status === AgentPresence::BUSY) {
            $this->presence->set($agent, AgentPresence::WRAP_UP);
        }
    }

    /** The whole call is over. */
    public function ended(VoiceCall $call): void
    {
        $call->refresh();
        if ($call->ended_at !== null) {
            return;
        }
        $status = match ($call->status) {
            VoiceCall::IN_PROGRESS => VoiceCall::COMPLETED,
            VoiceCall::RINGING => $call->isOutbound() ? VoiceCall::NO_ANSWER : VoiceCall::MISSED,
            default => $call->status,
        };
        $call->forceFill([
            'status' => $status,
            'ended_at' => now(),
            'duration_seconds' => $call->answered_at ? max(0, (int) $call->answered_at->diffInSeconds(now())) : null,
        ])->save();

        if ($agent = $call->answeredBy ?? $call->placedBy) {
            if ($agent->phone_status === AgentPresence::BUSY) {
                $this->presence->set($agent, AgentPresence::WRAP_UP);
            }
        }
        FinishVoiceCall::dispatch($call->id);
    }

    /**
     * An agent calls a customer from the browser, showing the business's number (D61). Calls out go
     * only to US and Canadian numbers, never premium-rate ones.
     *
     * @throws TelephonyUnavailable
     */
    public function dial(Organization $organization, User $agent, string $number): VoiceCall
    {
        $to = PhoneNumber::normalize($number);
        if ($to === null || ! preg_match('/^\+1\d{10}$/', $to) || preg_match('/^\+1(900|976)/', $to)) {
            throw new TelephonyUnavailable('Enter a US or Canadian phone number.');
        }
        $line = PhoneNumber::query()->forOrganization($organization)->active()->orderBy('id')->first();
        if (! $line) {
            throw new TelephonyUnavailable('This business has no phone line to call from.');
        }
        if (VoiceCall::withoutGlobalScopes()->live()->where(fn ($q) => $q->where('answered_by_user_id', $agent->id)->orWhere('placed_by_user_id', $agent->id))->exists()) {
            throw new TelephonyUnavailable('Finish your current call first.');
        }

        $customer = app(MatchOrCreateCustomer::class)->lookup($organization, $to, null)['customer'] ?? null;
        $call = VoiceCall::create([
            'organization_id' => $organization->id, 'phone_number_id' => $line->id, 'provider' => $this->provider->name(),
            'provider_call_sid' => 'pending-'.Str::ulid(), 'direction' => 'outbound', 'from_number' => $line->number, 'to_number' => $to,
            'status' => VoiceCall::RINGING, 'placed_by_user_id' => $agent->id, 'customer_id' => $customer?->id, 'started_at' => now(),
        ]);
        $this->presence->set($agent, AgentPresence::BUSY);
        $this->audit->record('call.placed', $call, new: ['to' => $to], organization: $organization, actor: $agent, label: PhoneNumber::pretty($to));

        return $call;
    }

    /** The person an agent called picked up. */
    public function calleeAnswered(VoiceCall $call): void
    {
        $now = now();
        VoiceCall::withoutGlobalScopes()->whereKey($call->id)->where('status', VoiceCall::RINGING)->update([
            'status' => VoiceCall::IN_PROGRESS, 'answered_by_user_id' => $call->placed_by_user_id, 'answered_at' => $now,
            'wait_seconds' => max(0, (int) $call->started_at->diffInSeconds($now)), 'updated_at' => $now,
        ]);
        $call->refresh();
    }

    /** Nobody at SureHelp answered, but the owner's phone did. */
    public function ownerAnswered(VoiceCall $call): void
    {
        $call->forceFill(['status' => VoiceCall::TRANSFERRED, 'transferred_at' => $call->transferred_at ?? now()])->save();
    }

    public function recordingReady(VoiceCall $call, string $recordingSid, ?int $seconds, bool $voicemail): void
    {
        $call->forceFill(['recording_sid' => $recordingSid, 'recording_seconds' => $seconds]
            + ($voicemail ? ['status' => VoiceCall::VOICEMAIL] : []))->save();

        if ($voicemail && $call->ended_at !== null) {
            FinishVoiceCall::dispatch($call->id); // the call ended before the message was saved: update its task
        }
    }

    /** One finished sentence from the live transcription. */
    public function heard(VoiceCall $call, string $speaker, string $text): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }
        DB::transaction(function () use ($call, $speaker, $text) {
            $locked = VoiceCall::withoutGlobalScopes()->lockForUpdate()->find($call->id);
            $lines = $locked->transcript ?? [];
            $lines[] = ['speaker' => $speaker === 'agent' ? 'agent' : 'caller', 'text' => mb_substr($text, 0, 2000), 'at' => now()->toIso8601String()];
            $locked->forceFill(['transcript' => $lines])->save();
        });
    }

    /** Transcription finished: write the summary (when the AI is set up and something was said). */
    public function transcriptFinished(VoiceCall $call): void
    {
        $call->refresh();
        if ($call->summary_status === null && ! empty($call->transcript)) {
            $call->forceFill(['summary_status' => 'queued'])->save();
            SummarizeVoiceCall::dispatch($call->id);
        }
    }

    /**
     * Send the caller to the business owner's number (a cold transfer: the agent drops off).
     *
     * @throws TelephonyUnavailable
     */
    public function transfer(VoiceCall $call, User $agent): void
    {
        $line = $call->phoneNumber;
        if ($call->isOutbound() || $call->status !== VoiceCall::IN_PROGRESS || $call->answered_by_user_id !== $agent->id) {
            throw new TelephonyUnavailable('This call can\'t be transferred any more.');
        }
        if (! $line?->transfer_number) {
            throw new TelephonyUnavailable('This business has no number to transfer calls to.');
        }

        $this->provider->redirectCall($call->provider_call_sid, route('webhooks.voice.transfer', $call));
        $call->forceFill(['status' => VoiceCall::TRANSFERRED, 'transferred_to' => $line->transfer_number, 'transferred_at' => now()])->save();
        $this->presence->set($agent, AgentPresence::WRAP_UP);
        $this->audit->record('call.transferred', $call, new: ['to' => $line->transfer_number], organization: $call->organization, actor: $agent, label: $call->fromDisplay());
    }
}

<?php

namespace App\Jobs;

use App\Actions\Notifications\NotifyOrganization;
use App\Actions\Tasks\CreateTask;
use App\Enums\TaskType;
use App\Models\BusinessProfile;
use App\Models\Organization;
use App\Models\PhoneNumber;
use App\Models\VoiceCall;
use App\Notifications\MissedVoiceCall;
use App\Services\Automation\AutomationEngine;
use App\Services\Telephony\Texting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * After a call (D60): a missed call or voicemail becomes a high-priority call-back task for the
 * business and its people are told; the caller may get a text back (D61). Runs again when a voicemail arrives after the call ended.
 */
class FinishVoiceCall implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $callId) {}

    public function handle(CreateTask $tasks, NotifyOrganization $notify, Texting $texting): void
    {
        $call = VoiceCall::withoutGlobalScopes()->with('customer')->find($this->callId);
        if (! $call || ! in_array($call->status, [VoiceCall::MISSED, VoiceCall::VOICEMAIL], true)) {
            return;
        }
        $organization = Organization::query()->find($call->organization_id);
        if (! $organization) {
            return;
        }

        $who = $call->customer?->fullName() ?: $call->fromDisplay();
        $when = $call->started_at->timezone($organization->timezoneOrDefault())->format('M j, g:i A');
        $details = $call->status === VoiceCall::VOICEMAIL
            ? "{$who} called at {$when} and left a voicemail. Listen to it on the call before calling back."
            : "{$who} called at {$when} and nobody was free to answer.";

        if ($call->task) {
            $call->task->forceFill(['description' => $details])->save();

            return;
        }

        $task = $tasks->handle($organization, [
            'type' => TaskType::Callback,
            'title' => 'Call back '.$who.($call->from_number && $call->customer ? ' · '.$call->fromDisplay() : ''),
            'description' => $details,
            'priority' => 'high',
            'customer_id' => $call->customer_id,
        ], null, 'phone');
        $call->forceFill(['task_id' => $task->id])->save();

        $notify->handle($organization, new MissedVoiceCall($call, $who), 'calls.view');
        $this->textBack($call, $organization, $texting);
        app(AutomationEngine::class)->fire('call_missed', $call);   // D80
    }

    /**
     * "Sorry we missed you" by text when the line has it on (D61): once per caller in 12 hours, and
     * the conversation continues in the Inbox.
     */
    private function textBack(VoiceCall $call, Organization $organization, Texting $texting): void
    {
        $line = $call->phoneNumber;
        if (! $line?->text_back || $call->texted_back_at || ! $call->from_number) {
            return;
        }
        $recently = VoiceCall::withoutGlobalScopes()->where('organization_id', $organization->id)->where('from_number', $call->from_number)
            ->where('texted_back_at', '>=', now()->subHours(12))->exists();
        if ($recently) {
            return;
        }
        $business = BusinessProfile::query()->forOrganization($organization)->value('display_name') ?: $organization->name;
        $message = $texting->sendAutomatic($organization, $call->from_number, str_replace('{business}', $business, $line->text_back_message ?: PhoneNumber::DEFAULT_TEXT_BACK), 'missed_call');
        if ($message?->status === 'sent') {
            $call->forceFill(['texted_back_at' => now()])->save();
        }
    }
}

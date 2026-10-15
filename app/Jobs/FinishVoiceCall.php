<?php

namespace App\Jobs;

use App\Actions\Notifications\NotifyOrganization;
use App\Actions\Tasks\CreateTask;
use App\Enums\TaskType;
use App\Models\Organization;
use App\Models\VoiceCall;
use App\Notifications\MissedVoiceCall;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * After a call (D60): a missed call or voicemail becomes a high-priority call-back task for the
 * business and its people are told. Runs again when a voicemail arrives after the call ended.
 */
class FinishVoiceCall implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $callId) {}

    public function handle(CreateTask $tasks, NotifyOrganization $notify): void
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
    }
}

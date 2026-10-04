<?php

namespace App\Actions\Tasks;

use App\Enums\TaskPriority;
use App\Enums\TaskType;
use App\Models\CallLog;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Services\Tasks\CallbackDueTime;
use Illuminate\Support\Str;

/**
 * A caller asked for a call back: give the business a task for it (spec §24).
 * Idempotent per call, so retries, edits and the backfill never duplicate it.
 */
class CreateCallbackTask
{
    public function __construct(
        private readonly CreateTask $create,
        private readonly CallbackDueTime $due,
    ) {}

    public function handle(CallLog $call, Organization $organization, ?User $agent = null, string $source = 'call'): Task
    {
        $existing = Task::query()->forOrganization($organization)
            ->where('call_log_id', $call->id)
            ->where('type', TaskType::Callback->value)
            ->first();

        if ($existing) {
            return $existing;
        }

        $caller = CallLog::display($call->caller_name);
        $phone = $call->caller_phone ? ' · '.$call->caller_phone : '';

        return $this->create->handle($organization, [
            'type' => TaskType::Callback,
            'title' => Str::limit("Call back {$caller}{$phone}", 250, ''),
            'description' => trim(Str::headline((string) $call->reason_for_call).'. '.trim((string) $call->notes), '. ') ?: null,
            'priority' => TaskPriority::High->value,
            'due_at' => $this->due->for($organization, $call->created_at),
            'customer_id' => $call->customer_id,
            'call_log_id' => $call->id,
        ], $agent, $source);
    }
}

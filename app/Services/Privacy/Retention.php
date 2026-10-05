<?php

namespace App\Services\Privacy;

use App\Enums\EscalationStatus;
use App\Enums\TaskStatus;
use App\Models\Appointment;
use App\Models\CallLog;
use App\Models\Customer;
use App\Models\CustomerTimelineEvent;
use App\Models\Escalation;
use App\Models\Organization;
use App\Models\Task;

/**
 * How long a business's history is kept (spec §56, task.md CMP-05). Each business chooses; older
 * calls, past appointments, finished tasks and escalations, timeline entries and archived customers
 * are deleted by the daily `data:retention` run. Open work is never deleted, whatever its age.
 */
class Retention
{
    /** Months a business can choose; null keeps everything. */
    public const CHOICES = [12, 24, 36, 60, 84];

    public const DEFAULT_MONTHS = 36;

    /**
     * @return array<string, int> what was deleted, by kind
     */
    public function apply(Organization $organization): array
    {
        if ($organization->retention_months === null) {
            return [];
        }

        $cutoff = now()->subMonths($organization->retention_months);

        return [
            'calls' => CallLog::withoutGlobalScopes()->forOrganization($organization)->where('created_at', '<', $cutoff)->delete(),
            'appointments' => Appointment::withoutGlobalScopes()->forOrganization($organization)->where('ends_at', '<', $cutoff)->delete(),
            'tasks' => Task::withoutGlobalScopes()->forOrganization($organization)
                ->whereIn('status', [TaskStatus::Completed->value, TaskStatus::Cancelled->value])->where('updated_at', '<', $cutoff)->delete(),
            'escalations' => Escalation::withoutGlobalScopes()->forOrganization($organization)
                ->where('status', EscalationStatus::Resolved->value)->where('resolved_at', '<', $cutoff)->delete(),
            'timeline' => CustomerTimelineEvent::withoutGlobalScopes()->forOrganization($organization)->where('occurred_at', '<', $cutoff)->delete(),
            'archived_customers' => Customer::onlyTrashed()->withoutGlobalScopes()->forOrganization($organization)->where('deleted_at', '<', $cutoff)->forceDelete(),
        ];
    }

    public static function label(?int $months): string
    {
        return match (true) {
            $months === null => 'Keep everything',
            $months % 12 === 0 => ($months / 12).' '.str('year')->plural($months / 12),
            default => "{$months} months",
        };
    }
}

<?php

namespace App\Services\Metrics;

use App\Enums\OutcomeCategory;
use App\Models\Appointment;
use App\Models\CallLog;
use App\Models\Customer;
use App\Models\Escalation;
use App\Models\Organization;
use App\Models\Task;
use App\Services\Calls\CallOutcomes;
use Carbon\CarbonImmutable;

/**
 * The morning email (spec NTF-04): what happened yesterday and what's waiting today, in the
 * business's timezone. Real counts only.
 */
class DailySummary
{
    public function __construct(private readonly CallOutcomes $outcomes) {}

    /**
     * @return array{date: string, calls: int, booked: int, missed: int, leads: int, appointments: list<array{time: string, title: string}>, appointment_count: int, follow_ups: int, overdue: int, escalations: int}
     */
    public function for(Organization $organization, ?CarbonImmutable $today = null): array
    {
        $timezone = $organization->timezoneOrDefault();
        $today = ($today ?? CarbonImmutable::now($timezone))->setTimezone($timezone)->startOfDay();
        $yesterday = [$today->subDay()->utc(), $today->subSecond()->utc()];

        $calls = CallLog::query()->forOrganization($organization)->whereBetween('created_at', $yesterday)->pluck('call_outcome');
        $booked = $this->outcomes->keys($organization, OutcomeCategory::Booked);
        $missed = $this->outcomes->keys($organization, OutcomeCategory::Missed);

        $appointments = Appointment::query()->forOrganization($organization)->blocking()
            ->whereBetween('starts_at', [$today->utc(), $today->endOfDay()->utc()])->orderBy('starts_at')->get(['title', 'starts_at']);

        return [
            'date' => $today->subDay()->format('l, M j'),
            'calls' => $calls->count(),
            'booked' => $calls->filter(fn ($o) => in_array($o, $booked, true))->count(),
            'missed' => $calls->filter(fn ($o) => in_array($o, $missed, true))->count(),
            'leads' => Customer::query()->forOrganization($organization)->whereBetween('created_at', $yesterday)->count(),
            'appointments' => $appointments->take(6)->map(fn (Appointment $a) => ['time' => $a->starts_at->setTimezone($timezone)->format('g:i A'), 'title' => $a->title])->values()->all(),
            'appointment_count' => $appointments->count(),
            'follow_ups' => Task::query()->forOrganization($organization)->open()->followUps()->count(),
            'overdue' => Task::query()->forOrganization($organization)->open()->overdue()->count(),
            'escalations' => Escalation::query()->forOrganization($organization)->active()->count(),
        ];
    }

    /** Nothing happened and nothing is waiting: no email worth sending. */
    public function isEmpty(array $summary): bool
    {
        return $summary['calls'] === 0 && $summary['appointment_count'] === 0 && $summary['follow_ups'] === 0 && $summary['escalations'] === 0;
    }
}

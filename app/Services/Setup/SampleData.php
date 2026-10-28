<?php

namespace App\Services\Setup;

use App\Enums\AppointmentStatus;
use App\Enums\CallOwnershipSource;
use App\Models\Appointment;
use App\Models\CallLog;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sample customers, answered calls, bookings and follow-ups for a new free trial (D97), so the
 * dashboard, calendar and results show what SureHelp does from day one. The owner removes them in
 * one click; the rows created are remembered on the business, so nothing real is ever touched.
 *
 * Created without model events: no automations, emails, texts or calendar syncs run for them. The
 * people use reserved example.com addresses and 555-01xx numbers, and never gave consent. The calls
 * are dated before the trial started, so they never count as usage.
 */
class SampleData
{
    /** [first, last, phone, reason, outcome, notes] */
    private const CALLS = [
        ['Maria', 'Lopez', '+12125550142', 'service-request', 'scheduled-appointment', 'Kitchen sink leaking under the cabinet. Booked the first morning slot.'],
        ['James', 'Carter', '+12125550118', 'appointment-scheduling', 'scheduled-appointment', 'Wants a quote for a new water heater. Prefers afternoons.'],
        ['Priya', 'Shah', '+12125550173', 'general-inquiry', 'provided-information', 'Asked about prices and the service area. Sent our hours.'],
        ['Tom', 'Becker', '+12125550127', 'emergency-service', 'escalated-to-client', 'No hot water since this morning. Passed to the owner right away.'],
        ['Linda', 'Nguyen', '+12125550164', 'reschedule-appointment', 'callback-requested', 'Needs to move Thursday\'s visit. Please call back after 5 PM.'],
        ['Robert', 'Hill', '+12125550109', 'billing-question', 'resolved-by-agent', 'Asked whether we take cards on site. Answered: yes.'],
        ['Maria', 'Lopez', '+12125550142', 'service-followup', 'provided-information', 'Called to say thanks: the leak is fixed.'],
        ['Kevin', 'Brooks', '+12125550181', 'wrong-number', 'wrong-number', 'Was looking for a pizza place.'],
    ];

    public function __construct(private readonly Audit $audit) {}

    public function has(Organization $organization): bool
    {
        return ! empty($organization->sample_data);
    }

    public function add(Organization $organization, User $owner): void
    {
        if ($this->has($organization)) {
            return;
        }

        $tz = $organization->timezoneOrDefault();
        $now = CarbonImmutable::now($tz);
        $made = ['customers' => [], 'call_logs' => [], 'appointments' => [], 'tasks' => []];

        Model::withoutEvents(function () use ($organization, $owner, $tz, $now, &$made) {
            $customers = [];
            foreach (self::CALLS as $i => [$first, $last, $phone, $reason, $outcome, $notes]) {
                $key = $first.$last;
                if ($reason !== 'wrong-number' && ! isset($customers[$key])) {
                    $customers[$key] = $this->make(Customer::class, [
                        'organization_id' => $organization->id,
                        'ulid' => (string) Str::ulid(),
                        'first_name' => $first,
                        'last_name' => $last,
                        'phone' => $phone,
                        'phone_e164' => Phone::normalize($phone),
                        'email' => Str::lower($first.'.'.$last).'@example.com',
                        'status' => $outcome === 'scheduled-appointment' ? 'customer' : 'lead',
                        'source' => 'call',
                        'notes' => 'Sample customer for your free trial.',
                        'last_activity_at' => now(),
                    ]);
                    $made['customers'][] = $customers[$key]->id;
                }

                // Spread over the last week, during the working day.
                $at = $now->subDays(8 - $i)->setTime(9 + ($i * 2) % 8, [5, 20, 35, 50][$i % 4]);
                $call = $this->make(CallLog::class, [
                    'call_id' => CallLog::generateCallId(),
                    'organization_id' => $organization->id,
                    'customer_id' => $customers[$key]->id ?? null,
                    'call_date' => $at->toDateString(),
                    'call_time' => $at->format('H:i:s'),
                    'caller_name' => "{$first} {$last}",
                    'caller_phone' => $phone,
                    'reason_for_call' => $reason,
                    'call_outcome' => $outcome,
                    'agent_name' => 'SureHelp receptionist',
                    'status' => $reason === 'wrong-number' ? 'spam' : ($outcome === 'scheduled-appointment' ? 'service-requested' : 'information-provided'),
                    'notes' => $notes,
                    'user_id' => $owner->id,
                    'ownership_source' => CallOwnershipSource::Direct,
                ]);
                $call->forceFill(['created_at' => $at->utc(), 'updated_at' => $at->utc()])->save();
                $made['call_logs'][] = $call->id;

                if ($outcome === 'scheduled-appointment') {
                    $made['appointments'][] = $this->booking($organization, $customers[$key], $call, $tz, $now->addDays(2 + count($made['appointments']))->setTime(10 + count($made['appointments']) * 3, 0), 'Visit: '.Str::before($notes, '.'))->id;
                }
                if ($outcome === 'callback-requested' || $outcome === 'escalated-to-client') {
                    $made['tasks'][] = $this->make(Task::class, [
                        'organization_id' => $organization->id,
                        'ulid' => (string) Str::ulid(),
                        'customer_id' => $customers[$key]->id,
                        'call_log_id' => $call->id,
                        'type' => $outcome === 'callback-requested' ? 'callback' : 'todo',
                        'title' => $outcome === 'callback-requested' ? "Call {$first} back after 5 PM" : "Help {$first} with no hot water",
                        'priority' => $outcome === 'escalated-to-client' ? 'high' : 'normal',
                        'status' => 'open',
                        'source' => 'call',
                        'due_at' => $now->addDay()->setTime(17, 0)->utc(),
                    ])->id;
                }
            }

            // One finished job, so results show a completed booking too.
            $made['appointments'][] = $this->booking($organization, $customers['RobertHill'], null, $tz, $now->subDays(3)->setTime(14, 0), 'Visit: faucet replacement', AppointmentStatus::Completed)->id;
        });

        $organization->forceFill(['sample_data' => json_encode($made)])->saveQuietly();
        $this->audit->record('organization.sample_data_added', $organization, new: array_map('count', $made), organization: $organization, actor: $owner, label: $organization->name);
    }

    /**
     * Deletes exactly the rows that were added, inside this business.
     *
     * @return int rows removed
     */
    public function remove(Organization $organization, User $by): int
    {
        $made = json_decode((string) $organization->sample_data, true);
        if (! is_array($made)) {
            return 0;
        }

        $removed = DB::transaction(function () use ($organization, $made) {
            $count = 0;
            // Children first, so nothing is left pointing at a deleted row.
            foreach (['tasks', 'appointments', 'call_logs', 'customers'] as $table) {
                $ids = array_map('intval', $made[$table] ?? []);
                if ($ids !== []) {
                    $count += DB::table($table)->where('organization_id', $organization->id)->whereIn('id', $ids)->delete();
                }
            }

            return $count;
        });

        $organization->forceFill(['sample_data' => null])->saveQuietly();
        $this->audit->record('organization.sample_data_removed', $organization, new: ['rows' => $removed], organization: $organization, actor: $by, label: $organization->name);

        return $removed;
    }

    /**
     * @template T of Model
     *
     * @param  class-string<T>  $class
     * @param  array<string, mixed>  $attributes
     * @return T
     */
    private function make(string $class, array $attributes): Model
    {
        $model = new $class;
        $model->forceFill($attributes)->save();

        return $model;
    }

    private function booking(Organization $organization, Customer $customer, ?CallLog $call, string $tz, CarbonImmutable $start, string $title, AppointmentStatus $status = AppointmentStatus::Confirmed): Appointment
    {
        $end = $start->addHour();

        return $this->make(Appointment::class, [
            'organization_id' => $organization->id,
            'ulid' => (string) Str::ulid(),
            'customer_id' => $customer->id,
            'call_log_id' => $call?->id,
            'title' => $title,
            'starts_at' => $start->utc(),
            'ends_at' => $end->utc(),
            'blocked_until' => $end->utc(),
            'timezone' => $tz,
            'status' => $status,
            'source' => 'agent',
            'notes' => 'Sample booking for your free trial.',
            'confirmed_at' => now(),
            'completed_at' => $status === AppointmentStatus::Completed ? $end->utc() : null,
            // Never reminded: these people aren't real.
            'reminder_sent_at' => now(),
        ]);
    }
}

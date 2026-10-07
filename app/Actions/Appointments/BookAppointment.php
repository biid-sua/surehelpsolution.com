<?php

namespace App\Actions\Appointments;

use App\Actions\Customers\RecordTimelineEvent;
use App\Actions\Notifications\NotifyOrganization;
use App\Enums\AppointmentStatus;
use App\Enums\NotificationEvent;
use App\Enums\TimelineEventType;
use App\Exceptions\SlotUnavailable;
use App\Models\Appointment;
use App\Models\BusinessLocation;
use App\Models\BusinessService;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\AppointmentActivity;
use App\Services\Rules\BusinessRules;
use App\Services\Scheduling\Availability;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Books an appointment (spec §16). One implementation for the portal, agents and the API.
 */
class BookAppointment
{
    public const DEFAULT_DURATION = 60;

    public function __construct(
        private readonly BookingGuard $guard,
        private readonly Availability $availability,
        private readonly Audit $audit,
        private readonly RecordTimelineEvent $timeline,
        private readonly NotifyOrganization $notify,
        private readonly BusinessRules $rules,
    ) {}

    /**
     * @param  array{starts_at: CarbonImmutable, duration_minutes?: ?int, service_id?: ?int, location_id?: ?int, customer_id?: ?int, call_log_id?: ?int, notes?: ?string, address?: ?string, status?: AppointmentStatus|string|null, title?: ?string}  $data  starts_at is an instant in any timezone
     * @param  bool  $strict  agents and automated booking must keep to opening hours and the business's rules; the business itself may book any time
     *
     * @throws ValidationException for bad references, past times, or (strict) times outside hours or against a rule
     * @throws SlotUnavailable when the time overlaps another booking
     */
    public function handle(Organization $organization, array $data, ?User $actor = null, string $source = 'portal', bool $strict = false): Appointment
    {
        $refs = self::references($organization, $data);
        $service = $refs['service'];
        $duration = (int) ($data['duration_minutes'] ?? $service->duration_minutes ?? self::DEFAULT_DURATION);
        $buffer = (int) ($service->buffer_minutes ?? 0);
        $start = CarbonImmutable::instance($data['starts_at'])->utc()->second(0);
        $end = $start->addMinutes($duration);

        self::checkTime($this->availability, $organization, $start, $duration, $strict);
        if ($strict && $violations = $this->rules->bookingViolations($organization, $start, $service, $refs['customer'], $data)) {
            throw ValidationException::withMessages(array_map(fn (string $m) => [$m], $violations));
        }

        $status = $data['status'] ?? AppointmentStatus::Confirmed;
        $status = $status instanceof AppointmentStatus ? $status : AppointmentStatus::from($status);
        if (! $status->blocksTime()) {
            throw ValidationException::withMessages(['status' => ['New appointments are confirmed, pending or tentative.']]);
        }
        // Approval mode (CAL-08): bookings by SureHelp agents wait for the business to approve them.
        if ($status === AppointmentStatus::Confirmed && self::needsApproval($organization, $actor)) {
            $status = AppointmentStatus::Pending;
        }

        $customer = $refs['customer'];
        $title = filled($data['title'] ?? null)
            ? trim((string) $data['title'])
            : trim(($service->name ?? 'Appointment').($customer ? ' · '.$customer->fullName() : ''));

        $appointment = $this->guard->claim($organization, $start, $end->addMinutes($buffer), $refs['location']?->id, null,
            fn () => Appointment::create([
                'organization_id' => $organization->id,
                'customer_id' => $customer?->id,
                'service_id' => $service?->id,
                'location_id' => $refs['location']?->id,
                'call_log_id' => $data['call_log_id'] ?? null,
                'title' => mb_substr($title, 0, 250),
                'starts_at' => $start,
                'ends_at' => $end,
                'blocked_until' => $end->addMinutes($buffer),
                'timezone' => $organization->timezoneOrDefault(),
                'status' => $status,
                'source' => $source,
                'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
                'address' => filled($data['address'] ?? null) ? trim((string) $data['address']) : null,
                'booked_by_user_id' => $actor?->id,
                'confirmed_at' => $status === AppointmentStatus::Confirmed ? now() : null,
            ]), $service?->id);

        $this->audit->record('appointment.created', $appointment, new: [
            'starts_at' => $appointment->starts_at->toIso8601String(),
            'ends_at' => $appointment->ends_at->toIso8601String(),
            'status' => $status->value,
            'service_id' => $appointment->service_id,
            'source' => $source,
        ], organization: $organization, actor: $actor, label: $appointment->title);

        if ($customer) {
            $this->timeline->handle($customer, TimelineEventType::AppointmentCreated, 'Appointment booked: '.($service->name ?? $appointment->title), $appointment->whenLabel(), $appointment, ['appointment' => $appointment->ulid], $actor?->id);
        }

        $approval = $status === AppointmentStatus::Pending && self::needsApproval($organization, $actor);
        $this->notify->handle($organization, new AppointmentActivity($appointment, NotificationEvent::AppointmentCreated,
            $approval ? 'Booked by '.$actor?->name.' from SureHelp. It waits for your approval: confirm it, or move or cancel it, in Appointments.' : null), 'appointments.view', $actor);

        return $appointment;
    }

    /** Booked by someone outside the business (a SureHelp agent or staff) while the owner approves those. */
    public static function needsApproval(Organization $organization, ?User $actor): bool
    {
        return $organization->approve_agent_bookings && $actor !== null
            && ! $organization->members()->whereKey($actor->id)->wherePivot('status', 'active')->exists();
    }

    /**
     * Resolves and checks the business's own records referenced by a booking.
     *
     * @param  array<string, mixed>  $data
     * @return array{service: ?BusinessService, location: ?BusinessLocation, customer: ?Customer}
     *
     * @throws ValidationException
     */
    public static function references(Organization $organization, array $data): array
    {
        $find = function (string $class, string $key, string $message) use ($organization, $data) {
            if (empty($data[$key])) {
                return null;
            }

            return $class::query()->forOrganization($organization)->whereKey($data[$key])->first()
                ?? throw ValidationException::withMessages([$key => [$message]]);
        };

        $service = $find(BusinessService::class, 'service_id', 'Choose one of your services.');
        if ($service && ! $service->is_bookable) {
            throw ValidationException::withMessages(['service_id' => ['This service can\'t be booked; it is quote or information only.']]);
        }

        return [
            'service' => $service,
            'location' => $find(BusinessLocation::class, 'location_id', 'Choose one of your locations.') ?? $service?->location,
            'customer' => $find(Customer::class, 'customer_id', 'Choose one of your customers.'),
        ];
    }

    /**
     * @throws ValidationException
     */
    public static function checkTime(Availability $availability, Organization $organization, CarbonImmutable $start, int $duration, bool $strict): void
    {
        if ($duration < 5 || $duration > 24 * 60) {
            throw ValidationException::withMessages(['duration_minutes' => ['Choose a length between 5 minutes and 24 hours.']]);
        }
        if ($start->lessThan(now()->subMinutes(5))) {
            throw ValidationException::withMessages(['starts_at' => ['That time has already passed.']]);
        }
        if ($strict && ! $availability->withinHours($organization, $start, $duration)) {
            throw ValidationException::withMessages(['starts_at' => ['That time is outside the business\'s opening hours.']]);
        }
    }
}

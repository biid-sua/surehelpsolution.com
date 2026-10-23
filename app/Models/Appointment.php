<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Jobs\PushAppointmentToCalendars;
use App\Jobs\SendAppointmentEmail;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\StoresUtc;
use App\Services\Automation\AutomationEngine;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A booked visit or meeting (spec §16).
 *
 * @property AppointmentStatus $status
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property Carbon $blocked_until
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $cancelled_at
 * @property array<string, mixed>|null $external_refs
 */
class Appointment extends Model
{
    use BelongsToOrganization, StoresUtc;

    /** Where an appointment happens (D56). Null on bookings made before structured locations. */
    public const LOCATION_ADDRESS = 'address';

    public const LOCATION_BUSINESS = 'business';

    public const LOCATION_REMOTE = 'remote';

    public const SOURCES = ['portal', 'agent', 'api', 'chatbot', 'calendar', 'ai', 'website'];

    protected $fillable = [
        'organization_id', 'customer_id', 'service_id', 'location_id', 'call_log_id', 'title', 'starts_at', 'ends_at',
        'blocked_until', 'timezone', 'status', 'source', 'notes', 'address', 'booked_by_user_id', 'confirmed_at',
        'location_mode', 'place_name', 'place_type', 'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country', 'customer_location_id',
        'completed_at', 'cancelled_at', 'cancellation_reason', 'external_refs',
    ];

    protected $attributes = [
        'status' => 'confirmed',
        'source' => 'portal',
    ];

    protected function casts(): array
    {
        return [
            'status' => AppointmentStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'blocked_until' => 'datetime',
            'confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'external_refs' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Appointment $appointment) {
            $appointment->ulid ??= (string) Str::ulid();
        });

        // Automations (D80): a new booking, and any "coming up" steps for it. Imports don't count.
        static::created(function (Appointment $appointment) {
            if ($appointment->source !== 'import'
                && in_array($appointment->status, [AppointmentStatus::Pending, AppointmentStatus::Tentative, AppointmentStatus::Confirmed], true)) {
                DB::afterCommit(function () use ($appointment) {
                    $engine = app(AutomationEngine::class);
                    $engine->fire('appointment_booked', $appointment);
                    $engine->fire('appointment_upcoming', $appointment);
                });
            }
        });

        // Connected calendars follow every booking change, once the booking has committed (spec §17).
        static::saved(function (Appointment $appointment) {
            $relevant = $appointment->wasRecentlyCreated || $appointment->wasChanged(['starts_at', 'ends_at', 'status', 'title', 'notes', 'address']);

            // The customer hears about it by email, once the change has committed (spec §26).
            $email = match (true) {
                $appointment->status === AppointmentStatus::Cancelled && ($appointment->wasRecentlyCreated || $appointment->wasChanged('status')) => $appointment->wasRecentlyCreated ? null : 'appointment_cancelled',
                $appointment->status === AppointmentStatus::Confirmed && ($appointment->wasRecentlyCreated || $appointment->wasChanged('status')) => 'appointment_confirmed',
                $appointment->status === AppointmentStatus::Confirmed && $appointment->wasChanged('starts_at') => 'appointment_changed',
                default => null,
            };
            if ($email) {
                if ($email === 'appointment_changed') {
                    $appointment->forceFill(['reminder_sent_at' => null])->saveQuietly();   // remind about the new time
                }
                SendAppointmentEmail::dispatch($appointment->id, $email)->afterCommit();
            }

            // Automations (D50, D80): completed, no-show or cancelled; "coming up" steps follow a moved time.
            $live = in_array($appointment->status, [AppointmentStatus::Pending, AppointmentStatus::Tentative, AppointmentStatus::Confirmed], true);
            $trigger = $appointment->wasChanged('status') ? match ($appointment->status) {
                AppointmentStatus::Completed => 'appointment_completed',
                AppointmentStatus::Cancelled => 'appointment_cancelled',
                AppointmentStatus::NoShow => 'appointment_no_show',
                default => null,
            } : null;
            if ($trigger) {
                DB::afterCommit(fn () => app(AutomationEngine::class)->fire($trigger, $appointment));
            }
            if ($live && $appointment->wasChanged('starts_at')) {
                DB::afterCommit(function () use ($appointment) {
                    $engine = app(AutomationEngine::class);
                    $engine->fire('appointment_rescheduled', $appointment);
                    $engine->rescheduled($appointment);
                });
            }

            if ($relevant && CalendarConnection::withoutGlobalScopes()->where('organization_id', $appointment->organization_id)
                ->where('status', CalendarConnection::STATUS_ACTIVE)->whereNotNull('write_calendar_id')->exists()) {
                // Web requests: straight after the response is sent, so the calendar has it within seconds.
                // Console and queue work (and tests): through the queue as before.
                app()->runningInConsole()
                    ? PushAppointmentToCalendars::dispatch($appointment->id)->afterCommit()
                    : PushAppointmentToCalendars::dispatchAfterResponse($appointment->id);
            }
        });
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /** @return BelongsTo<BusinessService, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(BusinessService::class, 'service_id')->withTrashed();
    }

    /** @return BelongsTo<BusinessLocation, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class, 'location_id');
    }

    /** @return BelongsTo<CallLog, $this> */
    public function call(): BelongsTo
    {
        return $this->belongsTo(CallLog::class, 'call_log_id');
    }

    /** @return BelongsTo<User, $this> */
    public function bookedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'booked_by_user_id');
    }

    /**
     * Holding their slot (pending, tentative, confirmed).
     *
     * @param  Builder<Appointment>  $query
     */
    public function scopeBlocking(Builder $query): void
    {
        $query->whereIn('status', AppointmentStatus::blockingValues());
    }

    /**
     * Overlapping [start, blockedUntil): touching end-to-start is not an overlap.
     *
     * @param  Builder<Appointment>  $query
     */
    public function scopeOverlapping(Builder $query, CarbonInterface $start, CarbonInterface $blockedUntil): void
    {
        $query->where('starts_at', '<', $blockedUntil)->where('blocked_until', '>', $start);
    }

    /**
     * Competing for the same calendar: the same location, or anything without one (a single shared calendar).
     *
     * @param  Builder<Appointment>  $query
     */
    public function scopeCompetingWith(Builder $query, ?int $locationId): void
    {
        if ($locationId !== null) {
            $query->where(fn (Builder $q) => $q->whereNull('location_id')->orWhere('location_id', $locationId));
        }
    }

    public function localStart(): CarbonImmutable
    {
        return CarbonImmutable::instance($this->starts_at)->setTimezone($this->organization?->timezoneOrDefault() ?? $this->timezone);
    }

    public function localEnd(): CarbonImmutable
    {
        return CarbonImmutable::instance($this->ends_at)->setTimezone($this->organization?->timezoneOrDefault() ?? $this->timezone);
    }

    /** "Tue 14 Oct, 2:00 – 3:30 PM" in the business's time. */
    /** Where it happens, for people: "Remote (no address)", "Main office", or "Lake house · 12 Main St, Austin, TX 78701" (D56). */
    public function whereLabel(): ?string
    {
        if ($this->location_mode === self::LOCATION_REMOTE) {
            return 'Remote (no address)';
        }
        $label = collect([$this->place_name, in_array($this->location_mode, [null, self::LOCATION_BUSINESS], true) ? $this->location?->name : null, $this->address])
            ->filter(fn ($part) => filled($part))->unique()->implode(' · ');

        return $label !== '' ? $label : null;
    }

    public function whenLabel(): string
    {
        $start = $this->localStart();
        $end = $this->localEnd();

        return $start->format('D j M, g:i').($start->format('A') === $end->format('A') ? '' : ' '.$start->format('A'))
            .' – '.$end->format('g:i A');
    }

    public function durationMinutes(): int
    {
        return (int) $this->starts_at->diffInMinutes($this->ends_at);
    }
}

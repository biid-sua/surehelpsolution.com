<?php

namespace App\Actions\Appointments;

use App\Exceptions\SlotUnavailable;
use App\Models\Appointment;
use App\Models\CalendarBusyBlock;
use App\Models\Organization;
use App\Services\Calendar\CalendarManager;
use App\Services\Scheduling\Availability;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The one place where time is claimed (spec §88: two agents booking 2:00 PM at once, only one succeeds).
 *
 * Inside a transaction the business's row is locked FOR UPDATE, so bookings for one business are
 * serialized; the overlap check and the write then happen with no other booking in between.
 * Different businesses never wait on each other.
 */
class BookingGuard
{
    public function __construct(private readonly Availability $availability) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T
     *
     * @throws SlotUnavailable
     */
    public function claim(
        Organization $organization,
        CarbonImmutable $start,
        CarbonImmutable $blockedUntil,
        ?int $locationId,
        ?int $ignoreAppointmentId,
        Closure $write,
        ?int $serviceId = null,
    ): mixed {
        try {
            return DB::transaction(function () use ($organization, $start, $blockedUntil, $locationId, $ignoreAppointmentId, $write) {
                Organization::query()->whereKey($organization->getKey())->lockForUpdate()->first();

                $conflicts = Appointment::query()->forOrganization($organization)
                    ->blocking()
                    ->overlapping($start->utc(), $blockedUntil->utc())
                    ->competingWith($locationId)
                    ->when($ignoreAppointmentId, fn ($q) => $q->whereKeyNot($ignoreAppointmentId))
                    ->orderBy('starts_at')
                    ->get();

                if ($conflicts->isNotEmpty()) {
                    throw new SlotUnavailable($conflicts);
                }

                // Busy in the business's own Google / Microsoft calendar (mirrored, spec §17).
                $external = CalendarBusyBlock::query()->forOrganization($organization)
                    ->overlapping($start->utc(), $blockedUntil->utc())->with('connection:id,provider')->first();
                if ($external) {
                    throw new SlotUnavailable(collect(), externalCalendar: app(CalendarManager::class)->provider($external->connection->provider ?? 'google')->label());
                }

                return $write();
            });
        } catch (SlotUnavailable $e) {
            $duration = (int) $start->diffInMinutes($blockedUntil);
            $e->suggestions = $this->availability->nextSlots($organization, $start, $duration, 0, $locationId, $ignoreAppointmentId, serviceId: $serviceId);

            throw $e;
        }
    }
}

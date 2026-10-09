<?php

namespace App\Services\Calendar;

use App\Exceptions\CalendarAuthorizationLost;
use App\Models\AgentCalendarConnection;
use App\Models\AgentCalendarEvent;
use App\Models\AgentDutySchedule;
use App\Services\Calendar\Data\BusyInterval;
use App\Services\Calendar\Data\EventPayload;
use App\Services\Calendar\Data\EventRef;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * An agent's own calendar (D54):
 *  - busy times are read live for the range on screen (cached for a few minutes), never stored;
 *  - SureHelp shifts are copied in: created, updated when the plan changes, deleted when removed.
 * Shifts belong to SureHelp, so our copy always wins over edits made in the external calendar.
 */
class AgentCalendarSync
{
    /** How far ahead shifts are copied. */
    public const DAYS_AHEAD = 60;

    private const BUSY_CACHE_SECONDS = 300;

    public function __construct(private readonly CalendarManager $calendars) {}

    /**
     * Busy periods in the chosen calendars, with the calendar's name.
     *
     * @return list<array{interval: BusyInterval, calendar: string}>
     */
    public function busy(AgentCalendarConnection $connection, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! $connection->isActive() || ($connection->busy_calendar_ids ?? []) === []) {
            return [];
        }
        $key = "agent-calendar-busy:{$connection->id}:{$connection->updated_at?->timestamp}:{$from->timestamp}:{$to->timestamp}";

        return Cache::remember($key, self::BUSY_CACHE_SECONDS, function () use ($connection, $from, $to) {
            $busy = [];
            try {
                $token = $this->calendars->accessToken($connection);
                $provider = $this->calendars->provider($connection->provider);
                foreach ($connection->busy_calendar_ids ?? [] as $calendarId) {
                    foreach ($provider->busy($token, $calendarId, $from, $to) as $interval) {
                        $busy[] = ['interval' => $interval, 'calendar' => $connection->calendarName($calendarId) ?? 'Calendar'];
                    }
                }
            } catch (CalendarAuthorizationLost) {
                return [];
            } catch (Throwable $e) {
                report($e);
                $connection->forceFill(['last_error' => 'Busy times could not be read just now.'])->saveQuietly();

                return [];
            }

            return $busy;
        });
    }

    /**
     * Copies the agent's upcoming shifts into the chosen calendar and removes copies of shifts that
     * no longer exist. Returns how many events were created, changed or removed.
     */
    public function pushShifts(AgentCalendarConnection $connection): int
    {
        if (! $connection->isActive() || ! $connection->push_shifts || ! $connection->write_calendar_id) {
            return 0;
        }

        try {
            $token = $this->calendars->accessToken($connection);
        } catch (CalendarAuthorizationLost) {
            return 0;
        }
        $provider = $this->calendars->provider($connection->provider);
        $calendarId = (string) $connection->write_calendar_id;
        $timezone = $connection->user->timezoneOrDefault();
        $now = CarbonImmutable::now();
        $shifts = AgentDutySchedule::query()->active()->forAgent($connection->user_id)
            ->where('end_datetime', '>', $now)->where('start_datetime', '<', $now->addDays(self::DAYS_AHEAD))
            ->get()->keyBy('id');
        $copies = $connection->events()->get()->keyBy('shift_id');
        $changes = 0;
        $failed = false;

        foreach ($shifts as $shift) {
            $payload = $this->payload($shift, $timezone);
            $fingerprint = sha1(implode('|', [$calendarId, $payload->title, $payload->start->toIso8601String(), $payload->end->toIso8601String(), $payload->description]));
            $copy = $copies->get($shift->id);
            try {
                if ($copy && $copy->calendar_id === $calendarId) {
                    if ($copy->fingerprint !== $fingerprint) {
                        $ref = $provider->updateEvent($token, $calendarId, new EventRef($copy->external_id), $payload);
                        $copy->update(['external_id' => $ref->id, 'fingerprint' => $fingerprint]);
                        $changes++;
                    }

                    continue;
                }
                if ($copy) {
                    // The agent chose another calendar: move the copy there.
                    $provider->deleteEvent($token, $copy->calendar_id, new EventRef($copy->external_id));
                    $copy->delete();
                }
                $ref = $provider->createEvent($token, $calendarId, $payload);
                AgentCalendarEvent::create(['connection_id' => $connection->id, 'shift_id' => $shift->id, 'external_id' => $ref->id, 'calendar_id' => $calendarId, 'fingerprint' => $fingerprint]);
                $changes++;
            } catch (CalendarAuthorizationLost) {
                return $changes;
            } catch (Throwable $e) {
                report($e);
                $failed = true;
            }
        }

        // Copies of shifts that were removed, switched off or moved to another agent.
        foreach ($copies->except($shifts->keys()->all()) as $copy) {
            try {
                $provider->deleteEvent($token, $copy->calendar_id, new EventRef($copy->external_id));
                $copy->delete();
                $changes++;
            } catch (CalendarAuthorizationLost) {
                return $changes;
            } catch (Throwable $e) {
                report($e);
                $failed = true;
            }
        }

        $connection->forceFill(['last_synced_at' => now(), 'last_error' => $failed ? 'Some shifts could not be copied. We\'ll try again.' : null])->saveQuietly();

        return $changes;
    }

    /** Removes every copied shift (copying turned off, or the calendar disconnected). Best effort. */
    public function removeShifts(AgentCalendarConnection $connection): void
    {
        try {
            $token = $this->calendars->accessToken($connection);
            $provider = $this->calendars->provider($connection->provider);
            foreach ($connection->events()->get() as $copy) {
                $provider->deleteEvent($token, $copy->calendar_id, new EventRef($copy->external_id));
                $copy->delete();
            }
        } catch (Throwable $e) {
            report($e);
        }
        $connection->events()->delete();
    }

    private function payload(AgentDutySchedule $shift, string $timezone): EventPayload
    {
        $title = trim((string) $shift->title) !== '' ? 'SureHelp shift: '.$shift->title : 'SureHelp shift';

        return new EventPayload(
            title: $title,
            start: CarbonImmutable::parse($shift->start_datetime),
            end: CarbonImmutable::parse($shift->end_datetime),
            timezone: $timezone,
            description: trim(implode("\n\n", array_filter([$shift->description, 'Planned in the SureHelp duty schedule. Changes there update this event.']))),
        );
    }
}

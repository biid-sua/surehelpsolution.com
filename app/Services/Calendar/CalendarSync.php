<?php

namespace App\Services\Calendar;

use App\Exceptions\CalendarAuthorizationLost;
use App\Exceptions\CalendarEventChanged;
use App\Models\Appointment;
use App\Models\CalendarBusyBlock;
use App\Models\CalendarConnection;
use App\Services\Calendar\Data\EventPayload;
use App\Services\Calendar\Data\EventRef;
use App\Services\Calendar\Data\PushChannel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Two-way sync between SureHelp appointments and connected calendars (spec §17, CAL-03).
 *
 *  - Out: appointments are created, moved and removed in the business's chosen calendar. An event someone
 *    edited in that calendar is never overwritten; the clash is recorded instead (spec §16).
 *  - In: busy times from the chosen calendars are mirrored (times only), so availability and the
 *    double-booking guard work without calling out to the provider during a call.
 */
class CalendarSync
{
    public function __construct(private readonly CalendarManager $calendars) {}

    /**
     * Refresh the mirrored busy times for one connection.
     *
     * @return int blocks stored
     */
    public function pullBusy(CalendarConnection $connection): int
    {
        try {
            $token = $this->calendars->accessToken($connection);
            $provider = $this->calendars->provider($connection->provider);
            $from = CarbonImmutable::now()->subDay()->startOfDay();
            $to = CarbonImmutable::now()->addDays((int) config('calendar.sync_days_ahead', 90));
            $ours = $this->ourEventIds($connection);

            $rows = [];
            foreach ($connection->busy_calendar_ids ?? [] as $calendarId) {
                foreach ($provider->busy($token, $calendarId, $from, $to) as $busy) {
                    if (in_array($busy->eventId, $ours, true)) {
                        continue;   // our own appointment: it already blocks the time
                    }
                    $rows[] = [
                        'organization_id' => $connection->organization_id,
                        'calendar_connection_id' => $connection->id,
                        'calendar_id' => $calendarId,
                        'external_event_id' => mb_substr($busy->eventId, 0, 255),
                        'starts_at' => $busy->start->utc()->format('Y-m-d H:i:s'),
                        'ends_at' => $busy->end->utc()->format('Y-m-d H:i:s'),
                        'all_day' => $busy->allDay,
                    ];
                }
            }

            DB::transaction(function () use ($connection, $rows) {
                CalendarBusyBlock::withoutGlobalScopes()->where('calendar_connection_id', $connection->id)->delete();
                foreach (array_chunk($rows, 500) as $chunk) {
                    CalendarBusyBlock::withoutGlobalScopes()->insert($chunk);
                }
            });

            $connection->forceFill(['last_synced_at' => now(), 'last_error' => null, 'status' => CalendarConnection::STATUS_ACTIVE])->save();

            return count($rows);
        } catch (CalendarAuthorizationLost) {
            return 0;   // flagged and notified by CalendarManager
        } catch (\Throwable $e) {
            report($e);
            $connection->forceFill(['last_error' => Str::limit($e->getMessage(), 500)])->save();

            return 0;
        }
    }

    /**
     * Bring every connected calendar of the appointment's business in line with the appointment.
     */
    public function pushAppointment(Appointment $appointment): void
    {
        $connections = CalendarConnection::withoutGlobalScopes()
            ->where('organization_id', $appointment->organization_id)
            ->where('status', CalendarConnection::STATUS_ACTIVE)
            ->whereNotNull('write_calendar_id')
            ->get();

        $refs = $appointment->external_refs ?? [];

        foreach ($connections as $connection) {
            $key = $connection->refKey();

            try {
                $token = $this->calendars->accessToken($connection);
                $provider = $this->calendars->provider($connection->provider);
                $existing = $refs[$key] ?? null;
                $calendarId = $existing['calendar_id'] ?? $connection->write_calendar_id;

                if (! $appointment->status->blocksTime()) {
                    // Cancelled: remove our event. Completed / no-show: leave the record as it was.
                    if ($existing && $appointment->status->value === 'cancelled' && empty($existing['conflict'])) {
                        $provider->deleteEvent($token, $calendarId, new EventRef($existing['event_id']));
                        unset($refs[$key]);
                    }

                    continue;
                }

                if (! empty($existing['conflict'])) {
                    continue;   // edited externally: a person resolves it, we don't fight over it
                }

                $payload = $this->payload($appointment);
                $ref = $existing
                    ? $provider->updateEvent($token, $calendarId, new EventRef($existing['event_id'], $existing['etag'] ?? null), $payload)
                    : $provider->createEvent($token, $calendarId, $payload);

                $refs[$key] = ['provider' => $connection->provider, 'calendar_id' => $calendarId, 'event_id' => $ref->id, 'etag' => $ref->etag, 'synced_at' => now()->toIso8601String()];
            } catch (CalendarEventChanged) {
                $refs[$key] = array_merge($refs[$key] ?? [], ['conflict' => true, 'conflict_at' => now()->toIso8601String()]);
            } catch (CalendarAuthorizationLost) {
                continue;
            } catch (\Throwable $e) {
                report($e);
                $connection->forceFill(['last_error' => Str::limit($e->getMessage(), 500)])->save();
            }
        }

        $appointment->forceFill(['external_refs' => $refs ?: null])->saveQuietly();
    }

    /**
     * Keep provider push notifications alive (Google channels last ~7 days, Graph subscriptions ~3).
     * Without HTTPS or when disabled, polling alone keeps calendars in sync.
     */
    public function ensurePush(CalendarConnection $connection): void
    {
        if (! config('calendar.push_enabled') || ! str_starts_with((string) config('app.url'), 'https://') || ! $connection->isActive()) {
            return;
        }

        try {
            $token = $this->calendars->accessToken($connection);
            $provider = $this->calendars->provider($connection->provider);
            $secret = $connection->push_secret ?: Str::random(40);
            $url = route('webhooks.calendar.'.$connection->provider);
            $wanted = array_values(array_unique(array_filter([...($connection->busy_calendar_ids ?? [])])));
            $kept = [];

            foreach ($connection->pushChannels() as $channel) {
                $fresh = in_array($channel->calendarId, $wanted, true) && $channel->expiresAt->greaterThan(now()->addDay());
                if ($fresh) {
                    $kept[$channel->calendarId] = $channel;
                } else {
                    rescue(fn () => $provider->stopWatch($token, $channel), report: false);
                }
            }

            foreach ($wanted as $calendarId) {
                if (! isset($kept[$calendarId]) && $channel = $provider->watch($token, $calendarId, $url, $secret)) {
                    $kept[$calendarId] = $channel;
                }
            }

            $connection->forceFill([
                'push_secret' => $secret,
                'push_channels' => array_values(array_map(fn (PushChannel $c) => $c->toArray(), $kept)),
            ])->save();
        } catch (CalendarAuthorizationLost) {
            return;
        } catch (\Throwable $e) {
            report($e);   // polling still covers us
        }
    }

    public function stopPush(CalendarConnection $connection): void
    {
        try {
            $token = $this->calendars->accessToken($connection);
            foreach ($connection->pushChannels() as $channel) {
                rescue(fn () => $this->calendars->provider($connection->provider)->stopWatch($token, $channel), report: false);
            }
        } catch (\Throwable) {
            // Access already gone: the channels expire by themselves.
        }

        $connection->forceFill(['push_channels' => null])->save();
    }

    /**
     * @return list<string> external ids of events we created for this connection
     */
    private function ourEventIds(CalendarConnection $connection): array
    {
        $key = $connection->refKey();

        return Appointment::withoutGlobalScopes()
            ->where('organization_id', $connection->organization_id)
            ->whereNotNull('external_refs')
            ->where('ends_at', '>=', now()->subDays(2))
            ->pluck('external_refs')
            ->map(fn ($refs) => (is_array($refs) ? $refs : (array) json_decode((string) $refs, true))[$key]['event_id'] ?? null)
            ->filter()
            ->values()
            ->all();
    }

    private function payload(Appointment $appointment): EventPayload
    {
        $appointment->loadMissing(['customer', 'service', 'location', 'organization']);
        $customer = $appointment->customer;

        $description = collect([
            $customer ? 'Customer: '.$customer->fullName().($customer->displayPhone() ? ' · '.$customer->displayPhone() : '') : null,
            $appointment->service ? 'Service: '.$appointment->service->name : null,
            $appointment->notes ? 'Notes: '.$appointment->notes : null,
            'Status: '.$appointment->status->label(),
            'Booked through SureHelp. Change or cancel it in SureHelp so our agents stay in sync.',
        ])->filter()->implode("\n");

        return new EventPayload(
            ($appointment->status->value === 'confirmed' ? '' : '['.$appointment->status->label().'] ').$appointment->title,
            CarbonImmutable::instance($appointment->starts_at),
            CarbonImmutable::instance($appointment->ends_at),
            $appointment->organization?->timezoneOrDefault() ?? $appointment->timezone,
            $description,
            $appointment->address ?? $appointment->location?->name,
            $appointment->ulid,
        );
    }
}

<?php

namespace App\Http\Controllers\Client;

use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\CalendarBusyBlock;
use App\Models\CallLog;
use App\Support\Calendar\CalendarSources;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FullCalendar event feed for the requested range, every event tagged with its source
 * (CalendarSources): SureHelp appointments (with the calendars each one is synced to), the
 * service visits agents noted on calls before appointments existed (all-day, D18), and busy
 * times from the connected Google and Microsoft calendars. `sources` limits the feed.
 */
class CalendarEventsController extends Controller
{
    /** Hard cap so a crafted range can't pull an organization's whole history. */
    private const MAX_EVENTS = 500;

    public function __invoke(Request $request, CurrentOrganization $current): JsonResponse
    {
        $organization = $current->get();
        abort_if($organization === null, 403);

        $validated = $request->validate([
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after:start'],
            'sources' => ['nullable', 'string', 'max:100'],
        ]);
        $wanted = array_key_exists('sources', $validated)
            ? array_values(array_intersect(explode(',', (string) $validated['sources']), CalendarSources::keys()))
            : CalendarSources::keys();
        $show = fn (string $source) => in_array($source, $wanted, true);

        $start = CarbonImmutable::parse($validated['start'])->toDateString();
        $end = CarbonImmutable::parse($validated['end'])->toDateString();

        $timezone = $organization->timezoneOrDefault();
        $appointments = ! $show(CalendarSources::BOOKINGS) ? collect() : Appointment::query()->forOrganization($organization)
            ->where('status', '!=', AppointmentStatus::Cancelled->value)
            ->where('starts_at', '<', CarbonImmutable::parse($end, $timezone)->endOfDay()->utc())
            ->where('ends_at', '>', CarbonImmutable::parse($start, $timezone)->startOfDay()->utc())
            ->orderBy('starts_at')
            ->limit(self::MAX_EVENTS)
            ->get()
            ->map(fn (Appointment $a) => [
                'id' => 'appt-'.$a->ulid,
                // Wall-clock business time without an offset: the calendar shows the business's day.
                'title' => $a->title,
                'start' => $a->starts_at->setTimezone($timezone)->format('Y-m-d\TH:i:s'),
                'end' => $a->ends_at->setTimezone($timezone)->format('Y-m-d\TH:i:s'),
                'url' => route('app.appointments.index', ['appointment' => $a->ulid]),
                'classNames' => ['tone-'.$a->status->tone(), 'src-'.CalendarSources::BOOKINGS],
                'extendedProps' => [
                    'kind' => 'appointment',
                    'source' => CalendarSources::BOOKINGS,
                    'status' => $a->status->label(),
                    // Which connected calendars hold a copy, and which ones someone edited there.
                    'synced' => collect($a->external_refs ?? [])->reject(fn (array $r) => ! empty($r['conflict']))->pluck('provider')->unique()->values(),
                    'conflicts' => collect($a->external_refs ?? [])->filter(fn (array $r) => ! empty($r['conflict']))->pluck('provider')->unique()->values(),
                ],
            ]);

        $visits = ! $show(CalendarSources::VISITS) ? collect() : CallLog::query()->forOrganization($organization)
            ->whereNotNull('service_date')
            ->whereBetween('service_date', [$start, $end])
            ->orderBy('service_date')
            ->limit(self::MAX_EVENTS)
            ->get()
            ->map(fn (CallLog $call) => [
                'id' => $call->call_id,
                'title' => trim(CallLog::display($call->caller_name).' — '.str($call->reason_for_call)->headline()),
                'start' => $call->service_date->toDateString(),
                'allDay' => true,
                'url' => route('app.calls.show', $call->call_id),
                'classNames' => ['tone-'.$call->statusTone(), 'src-'.CalendarSources::VISITS],
                'extendedProps' => ['kind' => 'visit', 'source' => CalendarSources::VISITS, 'window' => $call->service_window, 'status' => $call->statusLabel()],
            ]);

        // Busy times from connected calendars, tagged with the provider and calendar they came from.
        // Only times are stored, never what the events are (D21).
        $providers = array_values(array_filter(array_keys(CalendarSources::TAGS), $show));
        $busy = $providers === [] ? collect() : CalendarBusyBlock::query()->forOrganization($organization)
            ->whereHas('connection', fn ($q) => $q->whereIn('provider', $providers))
            ->with('connection:id,provider,calendars')
            ->overlapping(CarbonImmutable::parse($start, $timezone)->startOfDay()->utc(), CarbonImmutable::parse($end, $timezone)->endOfDay()->utc())
            ->orderBy('starts_at')
            ->limit(self::MAX_EVENTS)->get()
            ->map(fn (CalendarBusyBlock $b) => [
                'id' => 'busy-'.$b->id,
                'title' => 'Busy',
                'start' => $b->all_day ? $b->starts_at->format('Y-m-d') : $b->starts_at->setTimezone($timezone)->format('Y-m-d\TH:i:s'),
                'end' => $b->all_day ? $b->ends_at->format('Y-m-d') : $b->ends_at->setTimezone($timezone)->format('Y-m-d\TH:i:s'),
                'allDay' => $b->all_day,
                'classNames' => ['busy-block', 'src-'.$b->connection->provider],
                'extendedProps' => [
                    'kind' => 'busy',
                    'source' => $b->connection->provider,
                    'calendar' => $b->connection->calendarName($b->calendar_id),
                ],
            ]);

        return response()->json($appointments->concat($visits)->concat($busy)->values());
    }
}

<?php

namespace App\Http\Controllers\Agent;

use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Models\AgentCalendarConnection;
use App\Models\AgentDutySchedule;
use App\Models\Appointment;
use App\Models\ShiftRequest;
use App\Services\Calendar\AgentCalendarSync;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Event feed for My calendar (D54), in the agent's own time: their shifts, approved time off,
 * appointments they booked for companies they serve now, and busy times from their own Google
 * or Microsoft calendar (times only, never titles). Only ever the signed-in agent's own data.
 */
class CalendarEventsController extends Controller
{
    public const SOURCES = ['shifts', 'leave', 'bookings', 'google', 'microsoft'];

    /** Longest range served at once, so a crafted request can't pull years of data. */
    private const MAX_DAYS = 62;

    public function __invoke(Request $request, AgentCalendarSync $sync): JsonResponse
    {
        $validated = $request->validate([
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after:start'],
            'sources' => ['nullable', 'string', 'max:100'],
        ]);
        $user = $request->user();
        $timezone = $user->timezoneOrDefault();
        $from = CarbonImmutable::parse($validated['start'])->utc();
        $to = CarbonImmutable::parse($validated['end'])->utc();
        if ($from->diffInDays($to) > self::MAX_DAYS) {
            $to = $from->addDays(self::MAX_DAYS);
        }
        $wanted = array_key_exists('sources', $validated) ? array_intersect(explode(',', (string) $validated['sources']), self::SOURCES) : self::SOURCES;
        $show = fn (string $source) => in_array($source, $wanted, true);
        $local = fn ($time) => CarbonImmutable::parse($time)->setTimezone($timezone)->format('Y-m-d\TH:i:s');
        $events = [];

        if ($show('shifts')) {
            foreach (AgentDutySchedule::query()->active()->forAgent($user->id)->where('start_datetime', '<', $to)->where('end_datetime', '>', $from)->orderBy('start_datetime')->get() as $shift) {
                $off = $shift->shift_type === 'off';
                $events[] = [
                    'id' => 'shift-'.$shift->id,
                    'title' => $off ? 'Day off' : (trim((string) $shift->title) ?: 'On shift'),
                    'start' => $local($shift->start_datetime),
                    'end' => $local($shift->end_datetime),
                    'classNames' => [$off ? 'tone-neutral' : 'tone-success', 'src-shifts'],
                    'extendedProps' => ['kind' => 'shift', 'source' => 'shifts', 'status' => AgentDutySchedule::SHIFT_TYPES[$shift->shift_type] ?? null],
                ];
            }
        }

        if ($show('leave')) {
            $leave = ShiftRequest::query()->where('agent_id', $user->id)->where('type', ShiftRequest::LEAVE)->where('status', ShiftRequest::APPROVED)
                ->where('leave_from', '<=', $to->setTimezone($timezone)->toDateString())->where('leave_until', '>=', $from->setTimezone($timezone)->toDateString())->get();
            foreach ($leave as $request) {
                $events[] = [
                    'id' => 'leave-'.$request->id,
                    'title' => 'Time off',
                    'start' => CarbonImmutable::parse($request->leave_from)->toDateString(),
                    'end' => CarbonImmutable::parse($request->leave_until)->addDay()->toDateString(),
                    'allDay' => true,
                    'classNames' => ['tone-warning', 'src-leave'],
                    'extendedProps' => ['kind' => 'leave', 'source' => 'leave'],
                ];
            }
        }

        if ($show('bookings')) {
            $companies = $user->isAdmin() ? null : $user->assignedOrganizations()->pluck('organizations.id');
            $bookings = Appointment::withoutGlobalScopes()->where('booked_by_user_id', $user->id)
                ->when($companies !== null, fn ($q) => $q->whereIn('organization_id', $companies))
                ->where('status', '!=', AppointmentStatus::Cancelled->value)->where('starts_at', '<', $to)->where('ends_at', '>', $from)
                ->with('organization:id,name')->orderBy('starts_at')->limit(300)->get();
            foreach ($bookings as $appointment) {
                $events[] = [
                    'id' => 'appt-'.$appointment->ulid,
                    'title' => $appointment->title.' · '.$appointment->organization?->name,
                    'start' => $local($appointment->starts_at),
                    'end' => $local($appointment->ends_at),
                    'classNames' => ['tone-'.$appointment->status->tone(), 'src-bookings'],
                    'extendedProps' => ['kind' => 'booking', 'source' => 'bookings', 'status' => $appointment->status->label()],
                ];
            }
        }

        foreach (AgentCalendarConnection::query()->where('user_id', $user->id)->get() as $connection) {
            if (! $show($connection->provider)) {
                continue;
            }
            foreach ($sync->busy($connection, $from, $to) as $i => ['interval' => $busy, 'calendar' => $calendar]) {
                $events[] = [
                    'id' => 'busy-'.$connection->provider.'-'.$i,
                    'title' => 'Busy',
                    'start' => $busy->allDay ? $busy->start->toDateString() : $local($busy->start),
                    'end' => $busy->allDay ? $busy->end->toDateString() : $local($busy->end),
                    'allDay' => $busy->allDay,
                    'classNames' => ['busy-block', 'src-'.$connection->provider],
                    'extendedProps' => ['kind' => 'busy', 'source' => $connection->provider, 'calendar' => $calendar],
                ];
            }
        }

        return response()->json($events);
    }
}

<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\CallLog;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FullCalendar event feed: service visits of the current organization in the requested range.
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
        ]);

        $start = CarbonImmutable::parse($validated['start'])->toDateString();
        $end = CarbonImmutable::parse($validated['end'])->toDateString();

        $events = CallLog::query()->forOrganization($organization)
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
                'classNames' => ['tone-'.$call->statusTone()],
                'extendedProps' => ['window' => $call->service_window, 'status' => $call->statusLabel()],
            ]);

        return response()->json($events);
    }
}

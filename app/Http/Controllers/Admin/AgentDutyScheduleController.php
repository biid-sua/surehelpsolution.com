<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AgentDutySchedule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AgentDutyScheduleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // Only admins can access this
        if (! Auth::user()->isAdmin()) {
            abort(403, 'Unauthorized access');
        }

        $schedules = AgentDutySchedule::with('agent')->active()->get();
        $agents = User::where('role', 'agent')->get();

        // Check if this is an AJAX request (for dashboard integration)
        if (request()->ajax()) {
            return response()->json([
                'html' => view('admin.duty-schedules.partials.table-rows', compact('schedules'))->render(),
            ]);
        }

        return view('duty-schedules.index', compact('schedules', 'agents'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        // Only admins can access this
        if (! Auth::user()->isAdmin()) {
            abort(403, 'Unauthorized access');
        }

        $agents = User::where('role', 'agent')->get();

        return view('admin.duty-schedules.create', compact('agents'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        // Only admins can access this
        if (! Auth::user()->isAdmin()) {
            abort(403, 'Unauthorized access');
        }

        $request->validate([
            'agent_id' => 'required|exists:users,id',
            'title' => 'required|string|max:255',
            'start_datetime' => 'required|date',
            'end_datetime' => 'required|date|after:start_datetime',
            'description' => 'nullable|string',
            'shift_type' => 'required|in:morning,afternoon,evening,night,off',
        ]);

        // Check for conflicts
        if (AgentDutySchedule::hasConflictingSchedules(
            $request->agent_id,
            $request->start_datetime,
            $request->end_datetime
        )) {
            return back()
                ->withErrors(['start_datetime' => 'This agent already has a conflicting schedule during this time period.'])
                ->withInput();
        }

        // Create the schedule
        $schedule = AgentDutySchedule::create([
            'agent_id' => $request->agent_id,
            'title' => $request->title,
            'start_datetime' => $request->start_datetime,
            'end_datetime' => $request->end_datetime,
            'shift_type' => $request->shift_type,
            'description' => $request->description,
            'is_active' => true,
        ]);

        return redirect()->route('duty-schedules.index')
            ->with('success', 'Duty schedule created successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(AgentDutySchedule $dutySchedule)
    {
        // Only admins can access this
        if (! Auth::user()->isAdmin()) {
            abort(403, 'Unauthorized access');
        }

        $dutySchedule->load('agent');

        return view('admin.duty-schedules.show', compact('dutySchedule'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(AgentDutySchedule $dutySchedule)
    {
        // Only admins can access this
        if (! Auth::user()->isAdmin()) {
            abort(403, 'Unauthorized access');
        }

        $agents = User::where('role', 'agent')->get();

        return view('admin.duty-schedules.edit', compact('dutySchedule', 'agents'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, AgentDutySchedule $dutySchedule)
    {
        // Only admins can access this
        if (! Auth::user()->isAdmin()) {
            abort(403, 'Unauthorized access');
        }

        $request->validate([
            'agent_id' => 'required|exists:users,id',
            'title' => 'required|string|max:255',
            'start_datetime' => 'required|date',
            'end_datetime' => 'required|date|after:start_datetime',
            'shift_type' => 'required|in:morning,afternoon,evening,night,off',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        // Check for conflicts (exclude current record)
        if (AgentDutySchedule::hasConflictingSchedules(
            $request->agent_id,
            $request->start_datetime,
            $request->end_datetime,
            $dutySchedule->id
        )) {
            return back()
                ->withErrors(['start_datetime' => 'This agent already has a conflicting schedule during this time period.'])
                ->withInput();
        }

        $dutySchedule->update($request->all());

        return redirect()->route('duty-schedules.index')
            ->with('success', 'Duty schedule updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(AgentDutySchedule $dutySchedule)
    {
        // Only admins can access this
        if (! Auth::user()->isAdmin()) {
            abort(403, 'Unauthorized access');
        }

        $dutySchedule->delete();

        return redirect()->route('duty-schedules.index')
            ->with('success', 'Duty schedule deleted successfully.');
    }

    /**
     * Get duty schedules for calendar (API endpoint)
     */
    public function getCalendarData(Request $request)
    {
        // Allow admin to view any agent's schedule, or agent to view their own
        $requestedAgentId = $request->get('agent_id');
        if ($requestedAgentId && Auth::user()->isAdmin()) {
            $agentId = $requestedAgentId;
        } else {
            $agentId = Auth::id();
        }

        $start = $request->get('start', Carbon::now()->startOfMonth());
        $end = $request->get('end', Carbon::now()->endOfMonth());

        $schedules = AgentDutySchedule::getSchedulesForAgent($agentId, $start, $end);

        $events = [];

        foreach ($schedules as $schedule) {
            // Check if there are conflicting schedules
            $conflictingSchedules = AgentDutySchedule::active()
                ->forAgent($agentId)
                ->where('id', '!=', $schedule->id)
                ->where('start_datetime', '<', $schedule->end_datetime)
                ->where('end_datetime', '>', $schedule->start_datetime)
                ->count();

            $title = $schedule->title;
            if ($conflictingSchedules > 0) {
                $title .= ' ⚠️'; // Warning indicator for conflicts
            }

            // Check if it's an overnight shift (spans multiple days)
            $isOvernight = $schedule->start_datetime->format('Y-m-d') !== $schedule->end_datetime->format('Y-m-d');

            $events[] = [
                'id' => $schedule->id,
                'title' => $title,
                'start' => $schedule->start_datetime->toISOString(),
                'end' => $schedule->end_datetime->toISOString(),
                'backgroundColor' => $schedule->getShiftTypeColor(),
                'borderColor' => $conflictingSchedules > 0 ? '#ff6b6b' : $schedule->getShiftTypeColor(), // Red border for conflicts
                'extendedProps' => [
                    'type' => $schedule->shift_type,
                    'description' => $schedule->description,
                    'schedule_id' => $schedule->id,
                    'is_overnight' => $isOvernight,
                    'has_conflicts' => $conflictingSchedules > 0,
                    'conflict_count' => $conflictingSchedules,
                ],
            ];
        }

        return response()->json($events);
    }

    /**
     * Check for schedule conflicts (API endpoint for real-time validation)
     */
    public function checkConflicts(Request $request)
    {
        $request->validate([
            'agent_id' => 'required|exists:users,id',
            'start_datetime' => 'required|date',
            'end_datetime' => 'required|date|after:start_datetime',
            'exclude_id' => 'nullable|exists:agent_duty_schedules,id',
        ]);

        $agentId = $request->agent_id;
        $startDateTime = $request->start_datetime;
        $endDateTime = $request->end_datetime;
        $excludeId = $request->exclude_id;

        $hasConflicts = AgentDutySchedule::hasConflictingSchedules($agentId, $startDateTime, $endDateTime, $excludeId);

        if ($hasConflicts) {
            $conflictingSchedules = AgentDutySchedule::getConflictingSchedules($agentId, $startDateTime, $endDateTime, $excludeId);

            return response()->json([
                'has_conflicts' => true,
                'conflicting_schedules' => $conflictingSchedules->map(function ($schedule) {
                    return [
                        'id' => $schedule->id,
                        'title' => $schedule->title,
                        'start_datetime' => $schedule->start_datetime->format('Y-m-d H:i'),
                        'end_datetime' => $schedule->end_datetime->format('Y-m-d H:i'),
                        'shift_type' => $schedule->shift_type,
                    ];
                }),
            ]);
        }

        return response()->json([
            'has_conflicts' => false,
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentDutySchedule;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Duty schedules for the mobile API.
 *
 * The API contract uses `schedule_date` + `start_time` + `end_time` + `notes`,
 * while the table stores `start_datetime` / `end_datetime` / `description`
 * (shared with the web admin screens). This controller translates between the
 * two so existing mobile clients keep working.
 */
class DutyScheduleController extends Controller
{
    /**
     * List duty schedules (agents see only their own).
     */
    public function getSchedules(Request $request)
    {
        try {
            $user = Auth::user();

            $agentId = $request->get('agent_id');
            $dateFrom = $request->get('date_from');
            $dateTo = $request->get('date_to');
            $status = $request->get('status', 'all'); // all, active, inactive

            $query = AgentDutySchedule::with('agent');

            // Only schedulers see everyone's shifts; everyone else sees their own.
            if (! $user->hasPermissionIn('users.view')) {
                $query->where('agent_id', $user->id);
            } elseif ($agentId) {
                $query->where('agent_id', $agentId);
            }

            if ($dateFrom) {
                $query->where('end_datetime', '>=', Carbon::parse($dateFrom)->startOfDay());
            }
            if ($dateTo) {
                $query->where('start_datetime', '<=', Carbon::parse($dateTo)->endOfDay());
            }

            if ($status !== 'all') {
                $query->where('is_active', $status === 'active');
            }

            $schedules = $query
                ->orderBy('start_datetime', 'desc')
                ->get()
                ->map(fn (AgentDutySchedule $schedule) => $this->present($schedule) + [
                    'agent_name' => $schedule->agent->name ?? 'Unknown',
                    'agent_unique_id' => $schedule->agent->unique_id ?? null,
                ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'schedules' => $schedules,
                    'filters' => [
                        'agent_id' => $agentId,
                        'date_from' => $dateFrom,
                        'date_to' => $dateTo,
                        'status' => $status,
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->serverError('Failed to fetch duty schedules', $e);
        }
    }

    /**
     * Calendar events for duty schedules.
     */
    public function getCalendarData(Request $request)
    {
        try {
            $user = Auth::user();

            $start = $request->get('start', Carbon::now()->startOfMonth()->toDateString());
            $end = $request->get('end', Carbon::now()->endOfMonth()->toDateString());

            $query = AgentDutySchedule::with('agent')
                ->active()
                ->withinDateRange(Carbon::parse($start)->startOfDay(), Carbon::parse($end)->endOfDay());

            // Only schedulers see everyone's shifts; everyone else sees their own.
            if (! $user->hasPermissionIn('users.view')) {
                $query->where('agent_id', $user->id);
            }

            $calendarData = $query->get()->map(fn (AgentDutySchedule $schedule) => [
                'id' => $schedule->id,
                'title' => ($schedule->agent->name ?? 'Unknown').' - '.$schedule->shift_type,
                'start' => $schedule->start_datetime->toISOString(),
                'end' => $schedule->end_datetime->toISOString(),
                'agent_id' => $schedule->agent_id,
                'agent_name' => $schedule->agent->name ?? 'Unknown',
                'shift_type' => $schedule->shift_type,
                'notes' => $schedule->description,
                'color' => $this->getShiftColor($schedule->shift_type),
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'events' => $calendarData,
                    'date_range' => ['start' => $start, 'end' => $end],
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->serverError('Failed to fetch calendar data', $e);
        }
    }

    /**
     * Create duty schedule (Admin only).
     */
    public function createSchedule(Request $request)
    {
        try {
            $request->validate([
                'agent_id' => 'required|exists:users,id',
                'schedule_date' => 'required|date',
                'start_time' => 'required|date_format:H:i,H:i:s',
                'end_time' => 'required|date_format:H:i,H:i:s|after:start_time',
                'shift_type' => 'required|string|max:255',
                'title' => 'nullable|string|max:255',
                'notes' => 'nullable|string',
                'is_active' => 'nullable|boolean',
            ]);

            [$startAt, $endAt] = $this->window($request->schedule_date, $request->start_time, $request->end_time);

            if (AgentDutySchedule::hasConflictingSchedules($request->agent_id, $startAt, $endAt)) {
                return $this->conflict();
            }

            $schedule = AgentDutySchedule::create([
                'agent_id' => $request->agent_id,
                'title' => $request->input('title') ?: $this->defaultTitle($request->shift_type),
                'start_datetime' => $startAt,
                'end_datetime' => $endAt,
                'shift_type' => $request->shift_type,
                'description' => $request->notes,
                'is_active' => $request->boolean('is_active', true),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Duty schedule created successfully',
                'data' => ['schedule' => $this->present($schedule)],
            ]);
        } catch (ValidationException $e) {
            return $this->validationFailed($e);
        } catch (\Throwable $e) {
            return $this->serverError('Failed to create duty schedule', $e);
        }
    }

    /**
     * Update duty schedule (Admin only).
     */
    public function updateSchedule(Request $request, $id)
    {
        try {
            $schedule = AgentDutySchedule::find($id);
            if (! $schedule) {
                return response()->json(['success' => false, 'message' => 'Duty schedule not found'], 404);
            }

            $request->validate([
                'agent_id' => 'sometimes|exists:users,id',
                'schedule_date' => 'sometimes|date',
                'start_time' => 'sometimes|date_format:H:i,H:i:s',
                'end_time' => 'sometimes|date_format:H:i,H:i:s',
                'shift_type' => 'sometimes|string|max:255',
                'title' => 'sometimes|nullable|string|max:255',
                'notes' => 'sometimes|nullable|string',
                'is_active' => 'sometimes|boolean',
            ]);

            $agentId = $request->input('agent_id', $schedule->agent_id);
            [$startAt, $endAt] = $this->window(
                $request->input('schedule_date', $schedule->start_datetime->toDateString()),
                $request->input('start_time', $schedule->start_datetime->format('H:i')),
                $request->input('end_time', $schedule->end_datetime->format('H:i')),
            );

            if ($endAt->lte($startAt)) {
                throw ValidationException::withMessages([
                    'end_time' => ['The end time must be after the start time.'],
                ]);
            }

            if (AgentDutySchedule::hasConflictingSchedules($agentId, $startAt, $endAt, $schedule->id)) {
                return $this->conflict();
            }

            $updates = [
                'agent_id' => $agentId,
                'start_datetime' => $startAt,
                'end_datetime' => $endAt,
            ];
            if ($request->has('shift_type')) {
                $updates['shift_type'] = $request->shift_type;
            }
            if ($request->has('title')) {
                $updates['title'] = $request->input('title') ?: $this->defaultTitle($updates['shift_type'] ?? $schedule->shift_type);
            }
            if ($request->has('notes')) {
                $updates['description'] = $request->notes;
            }
            if ($request->has('is_active')) {
                $updates['is_active'] = $request->boolean('is_active');
            }

            $schedule->update($updates);

            return response()->json([
                'success' => true,
                'message' => 'Duty schedule updated successfully',
                'data' => ['schedule' => $this->present($schedule)],
            ]);
        } catch (ValidationException $e) {
            return $this->validationFailed($e);
        } catch (\Throwable $e) {
            return $this->serverError('Failed to update duty schedule', $e);
        }
    }

    /**
     * Delete duty schedule (Admin only).
     */
    public function deleteSchedule($id)
    {
        try {
            $schedule = AgentDutySchedule::find($id);
            if (! $schedule) {
                return response()->json(['success' => false, 'message' => 'Duty schedule not found'], 404);
            }

            $schedule->delete();

            return response()->json([
                'success' => true,
                'message' => 'Duty schedule deleted successfully',
            ]);
        } catch (\Throwable $e) {
            return $this->serverError('Failed to delete duty schedule', $e);
        }
    }

    /**
     * Check for schedule conflicts (Admin only).
     */
    public function checkConflicts(Request $request)
    {
        try {
            $request->validate([
                'agent_id' => 'required|exists:users,id',
                'schedule_date' => 'required|date',
                'start_time' => 'required|date_format:H:i,H:i:s',
                'end_time' => 'required|date_format:H:i,H:i:s|after:start_time',
                'exclude_id' => 'nullable|exists:agent_duty_schedules,id',
            ]);

            [$startAt, $endAt] = $this->window($request->schedule_date, $request->start_time, $request->end_time);

            $conflicts = AgentDutySchedule::getConflictingSchedules($request->agent_id, $startAt, $endAt, $request->exclude_id)
                ->load('agent')
                ->map(fn (AgentDutySchedule $schedule) => [
                    'id' => $schedule->id,
                    'agent_name' => $schedule->agent->name ?? 'Unknown',
                    'start_time' => $schedule->start_datetime->format('H:i'),
                    'end_time' => $schedule->end_datetime->format('H:i'),
                    'shift_type' => $schedule->shift_type,
                ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'has_conflicts' => $conflicts->isNotEmpty(),
                    'conflicts' => $conflicts,
                ],
            ]);
        } catch (ValidationException $e) {
            return $this->validationFailed($e);
        } catch (\Throwable $e) {
            return $this->serverError('Failed to check conflicts', $e);
        }
    }

    /**
     * API representation, in the field names the mobile app already uses.
     */
    private function present(AgentDutySchedule $schedule): array
    {
        return [
            'id' => $schedule->id,
            'agent_id' => $schedule->agent_id,
            'title' => $schedule->title,
            'schedule_date' => $schedule->start_datetime->toDateString(),
            'start_time' => $schedule->start_datetime->format('H:i'),
            'end_time' => $schedule->end_datetime->format('H:i'),
            'start_datetime' => $schedule->start_datetime->toISOString(),
            'end_datetime' => $schedule->end_datetime->toISOString(),
            'shift_type' => $schedule->shift_type,
            'is_active' => $schedule->is_active,
            'notes' => $schedule->description,
            'created_at' => $schedule->created_at,
            'updated_at' => $schedule->updated_at,
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function window(string $date, string $startTime, string $endTime): array
    {
        $day = Carbon::parse($date)->toDateString();

        return [
            Carbon::parse($day.' '.$startTime),
            Carbon::parse($day.' '.$endTime),
        ];
    }

    private function defaultTitle(string $shiftType): string
    {
        return ucfirst($shiftType).' Shift';
    }

    private function getShiftColor($shiftType)
    {
        return match (strtolower((string) $shiftType)) {
            'morning' => '#3498db',
            'afternoon' => '#e74c3c',
            'evening' => '#9b59b6',
            'night' => '#34495e',
            'full day' => '#2ecc71',
            default => '#95a5a6',
        };
    }

    private function conflict()
    {
        return response()->json([
            'success' => false,
            'message' => 'Schedule conflict detected. Agent already has a duty schedule for this time period.',
        ], 409);
    }

    private function validationFailed(ValidationException $e)
    {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $e->errors(),
        ], 422);
    }

    private function serverError(string $message, \Throwable $e)
    {
        Log::error($message, ['exception' => $e]);

        return response()->json(['success' => false, 'message' => $message], 500);
    }
}

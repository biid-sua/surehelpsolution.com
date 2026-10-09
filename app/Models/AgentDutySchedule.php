<?php

namespace App\Models;

use App\Observers\AgentDutyScheduleObserver;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Agents' own calendars follow shift changes (D54).
#[ObservedBy([AgentDutyScheduleObserver::class])]
class AgentDutySchedule extends Model
{
    public const SHIFT_TYPES = ['morning' => 'Morning', 'afternoon' => 'Afternoon', 'evening' => 'Evening', 'night' => 'Night', 'off' => 'Day off'];

    protected $fillable = [
        'agent_id',
        'title',
        'start_datetime',
        'end_datetime',
        'shift_type',
        'description',
        'is_active',
    ];

    protected $casts = [
        'start_datetime' => 'datetime',
        'end_datetime' => 'datetime',
        'is_active' => 'boolean',
    ];

    /**
     * Get the agent that owns the duty schedule.
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /**
     * Scope to get active schedules.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get schedules for a specific agent.
     */
    public function scopeForAgent($query, $agentId)
    {
        return $query->where('agent_id', $agentId);
    }

    /**
     * Scope to get schedules within a date range.
     */
    public function scopeWithinDateRange($query, $startDate, $endDate)
    {
        return $query->where(function ($q) use ($startDate, $endDate) {
            $q->where(function ($subQ) use ($startDate, $endDate) {
                // Schedule starts before or on end date and ends after or on start date
                $subQ->where('start_datetime', '<=', $endDate)
                    ->where('end_datetime', '>=', $startDate);
            });
        });
    }

    /**
     * Get shift type colors for calendar display.
     */
    public function getShiftTypeColor(): string
    {
        return match ($this->shift_type) {
            'morning' => '#3b82f6',
            'afternoon' => '#06b6d4',
            'evening' => '#f59e0b',
            'night' => '#8b5cf6',
            'off' => '#6b7280',
            default => '#6b7280'
        };
    }

    /**
     * Check if schedule is active on a given date.
     */
    public function isActiveOn(Carbon $date): bool
    {
        $dateStr = $date->format('Y-m-d');
        $startDateStr = $this->start_datetime->format('Y-m-d');
        $endDateStr = $this->end_datetime->format('Y-m-d');

        return $this->is_active && $dateStr >= $startDateStr && $dateStr <= $endDateStr;
    }

    /**
     * Get schedules for a specific date range and agent.
     */
    public static function getSchedulesForAgent($agentId, $startDate, $endDate)
    {
        return self::active()
            ->forAgent($agentId)
            ->withinDateRange($startDate, $endDate)
            ->orderBy('start_datetime', 'asc')
            ->get();
    }

    /**
     * Get schedules for a specific agent and date.
     * Returns all schedules for the given date.
     */
    public static function getSchedulesForDate($agentId, $date)
    {
        $startOfDay = Carbon::parse($date)->startOfDay();
        $endOfDay = Carbon::parse($date)->endOfDay();

        return self::active()
            ->forAgent($agentId)
            ->where('start_datetime', '<=', $endOfDay)
            ->where('end_datetime', '>=', $startOfDay)
            ->orderBy('start_datetime', 'asc')
            ->get();
    }

    /**
     * Check if there are conflicting schedules for the same agent within a datetime range.
     */
    public static function hasConflictingSchedules($agentId, $startDateTime, $endDateTime, $excludeId = null)
    {
        $query = self::active()
            ->forAgent($agentId)
            ->where(function ($q) use ($startDateTime, $endDateTime) {
                // Check for overlapping schedules
                $q->where(function ($subQ) use ($startDateTime, $endDateTime) {
                    // Schedule starts before our end time and ends after our start time
                    $subQ->where('start_datetime', '<', $endDateTime)
                        ->where('end_datetime', '>', $startDateTime);
                });
            });

        // Exclude current record when updating
        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    /**
     * Get conflicting schedules for display in error messages.
     */
    public static function getConflictingSchedules($agentId, $startDateTime, $endDateTime, $excludeId = null)
    {
        $query = self::active()
            ->forAgent($agentId)
            ->where(function ($q) use ($startDateTime, $endDateTime) {
                // Check for overlapping schedules
                $q->where(function ($subQ) use ($startDateTime, $endDateTime) {
                    // Schedule starts before our end time and ends after our start time
                    $subQ->where('start_datetime', '<', $endDateTime)
                        ->where('end_datetime', '>', $startDateTime);
                });
            });

        // Exclude current record when updating
        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->get();
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CallLog;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ClientDashboardController extends Controller
{
    /**
     * Get client dashboard summary data
     */
    public function getDashboardSummary(Request $request)
    {
        try {
            $user = Auth::user();

            // Validate user role
            if ($user->role !== 'client') {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Client role required.',
                ], 403);
            }

            $period = $request->get('period', 'daily'); // daily, weekly, monthly
            $now = Carbon::now();

            // Helper to get date ranges
            $ranges = [
                'daily' => [
                    'start' => $now->copy()->startOfDay(),
                    'end' => $now->copy()->endOfDay(),
                ],
                'weekly' => [
                    'start' => $now->copy()->startOfWeek(),
                    'end' => $now->copy()->endOfWeek(),
                ],
                'monthly' => [
                    'start' => $now->copy()->startOfMonth(),
                    'end' => $now->copy()->endOfMonth(),
                ],
            ];

            $range = $ranges[$period] ?? $ranges['daily'];

            // Calls of the client's organization only (docs/decisions.md D4)
            $clientQuery = $this->organizationCalls();

            // Get summary data for the period
            $totalCalls = (clone $clientQuery)->whereBetween('created_at', [$range['start'], $range['end']])->count();
            $serviceRequests = (clone $clientQuery)->whereBetween('created_at', [$range['start'], $range['end']])->where('service_request', true)->count();
            $totalScheduled = (clone $clientQuery)->whereBetween('created_at', [$range['start'], $range['end']])->where('call_outcome', 'scheduled-appointment')->count();
            $inProgress = (clone $clientQuery)->whereBetween('created_at', [$range['start'], $range['end']])->where('status', 'service-requested')->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'summary' => [
                        'total_calls' => $totalCalls,
                        'service_requests' => $serviceRequests,
                        'total_scheduled' => $totalScheduled,
                        'in_progress' => $inProgress,
                    ],
                    'period' => $period,
                    'period_range' => [
                        'start' => $range['start']->toISOString(),
                        'end' => $range['end']->toISOString(),
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch dashboard summary',
            ], 500);
        }
    }

    /**
     * Get client call history
     */
    public function getCallHistory(Request $request)
    {
        try {
            $user = Auth::user();

            if ($user->role !== 'client') {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Client role required.',
                ], 403);
            }

            $period = $request->get('period', 'all'); // Default to 'all' to show all calls
            $limit = $request->get('limit', 100);
            $offset = $request->get('offset', 0);

            // Build client query
            $clientQuery = $this->organizationCalls();

            // Apply period filtering only if not 'all'
            if ($period !== 'all') {
                $now = Carbon::now();
                $ranges = [
                    'daily' => [
                        'start' => $now->copy()->startOfDay(),
                        'end' => $now->copy()->endOfDay(),
                    ],
                    'weekly' => [
                        'start' => $now->copy()->startOfWeek(),
                        'end' => $now->copy()->endOfWeek(),
                    ],
                    'monthly' => [
                        'start' => $now->copy()->startOfMonth(),
                        'end' => $now->copy()->endOfMonth(),
                    ],
                ];

                $range = $ranges[$period] ?? null;
                if ($range) {
                    $clientQuery->whereBetween('created_at', [$range['start'], $range['end']]);
                }
            }

            // Get call logs
            $callLogs = $clientQuery
                ->orderBy('created_at', 'desc')
                ->offset($offset)
                ->limit($limit)
                ->get()
                ->map(function ($log) {
                    return [
                        'id' => $log->id,
                        'call_id' => $log->call_id ?? $log->id,
                        'callId' => $log->call_id ?? $log->id,
                        'date' => $log->created_at ? $log->created_at->format('m/d/Y') : null,
                        'time' => $log->created_at ? $log->created_at->format('H:i') : null,
                        'caller_name' => $log->caller_name,
                        'callerName' => $log->caller_name,
                        'caller_number' => $log->caller_phone,
                        'callerNumber' => $log->caller_phone,
                        'call_outcome' => $log->call_outcome,
                        'callOutcome' => $log->call_outcome,
                        'agent_name' => $log->agent_name,
                        'agentName' => $log->agent_name,
                        'scheduled_service' => $this->mapScheduledStatus($log),
                        'scheduledService' => $this->mapScheduledStatus($log),
                        'service_location' => $log->service_location,
                        'serviceLocation' => $log->service_location,
                        'service_window' => $log->service_window,
                        'serviceWindow' => $log->service_window,
                        'service_date' => $log->service_date,
                        'serviceDate' => $log->service_date,
                        'reason_for_call' => $log->reason_for_call,
                        'status' => $log->status,
                        'notes' => $log->notes,
                        'note' => $log->notes,
                    ];
                });

            // Get total count for pagination
            $totalCount = $clientQuery->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'call_logs' => $callLogs,
                    'callData' => $callLogs, // For frontend compatibility
                    'pagination' => [
                        'limit' => $limit,
                        'offset' => $offset,
                        'total_count' => $totalCount,
                        'has_more' => ($offset + $limit) < $totalCount,
                    ],
                    'period' => $period,
                    'period_info' => $period === 'all' ? 'All calls' : ucfirst($period).' calls',
                ],
            ]);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch call history',
            ], 500);
        }
    }

    /**
     * Get client service requests
     */
    public function getServiceRequests(Request $request)
    {
        try {
            $user = Auth::user();

            if ($user->role !== 'client') {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Client role required.',
                ], 403);
            }

            $status = $request->get('status', 'all'); // all, pending, in_progress, completed, scheduled
            $limit = $request->get('limit', 20);

            // Build client query
            $clientQuery = $this->organizationCalls()
                ->where('service_request', true);

            // Filter by status
            if ($status !== 'all') {
                switch ($status) {
                    case 'pending':
                        $clientQuery->whereIn('status', ['new', 'service-requested']);
                        break;
                    case 'in_progress':
                        $clientQuery->where('status', 'service-requested');
                        break;
                    case 'completed':
                        $clientQuery->where('status', 'completed');
                        break;
                    case 'scheduled':
                        $clientQuery->where('call_outcome', 'scheduled-appointment');
                        break;
                }
            }

            $serviceRequests = $clientQuery
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get()
                ->map(function ($log) {
                    return [
                        'id' => $log->id,
                        'call_id' => $log->call_id ?? $log->id,
                        'request_date' => $log->created_at ? $log->created_at->format('Y-m-d H:i') : null,
                        'service_date' => $log->service_date,
                        'service_window' => $log->service_window,
                        'service_location' => $log->service_location,
                        'status' => $this->mapScheduledStatus($log),
                        'agent_name' => $log->agent_name,
                        'reason_for_call' => $log->reason_for_call,
                        'notes' => $log->notes,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => [
                    'service_requests' => $serviceRequests,
                    'filters' => [
                        'status' => $status,
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch service requests',
            ], 500);
        }
    }

    /**
     * Get client profile information
     */
    public function getProfile()
    {
        try {
            $user = Auth::user();

            if ($user->role !== 'client') {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Client role required.',
                ], 403);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'phone' => $user->phone,
                        'unique_id' => $user->unique_id,
                        'is_active' => $user->is_active,
                        'created_at' => $user->created_at,
                        'updated_at' => $user->updated_at,
                    ],
                    // Additive (docs/decisions.md D7): the business this user belongs to.
                    'organization' => $this->organizationPayload(),
                ],
            ]);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch profile',
            ], 500);
        }
    }

    /**
     * Update client profile
     */
    public function updateProfile(Request $request)
    {
        try {
            $user = Auth::user();

            if ($user->role !== 'client') {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Client role required.',
                ], 403);
            }

            $request->validate([
                'name' => 'sometimes|string|max:255',
                'phone' => 'sometimes|nullable|string|max:20',
            ]);

            $user->update($request->only(['name', 'phone']));

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'phone' => $user->phone,
                        'unique_id' => $user->unique_id,
                        'is_active' => $user->is_active,
                        'updated_at' => $user->updated_at,
                    ],
                ],
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update profile',
            ], 500);
        }
    }

    /**
     * Get client calendar/schedule data for calendar display
     */
    public function getCalendarData(Request $request)
    {
        try {
            $user = Auth::user();

            if ($user->role !== 'client') {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Client role required.',
                ], 403);
            }

            $start = $request->get('start', Carbon::now()->startOfMonth()->format('Y-m-d'));
            $end = $request->get('end', Carbon::now()->endOfMonth()->format('Y-m-d'));

            // Build client query
            $clientQuery = $this->organizationCalls()
                ->where('service_request', true)
                ->where(function ($q) {
                    $q->whereNotNull('service_date')
                        ->orWhere('call_outcome', 'scheduled-appointment');
                });

            // Get service requests with calendar data
            $serviceRequests = $clientQuery
                ->whereBetween('created_at', [$start, $end])
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($log) {
                    $serviceDate = $log->service_date ? Carbon::parse($log->service_date) : $log->created_at;
                    $serviceTime = $this->parseServiceTime($log->service_window);

                    return [
                        'id' => $log->id,
                        'call_id' => $log->call_id ?? $log->id,
                        'title' => $log->call_outcome ?? 'Service Request',
                        'start' => $serviceDate->format('Y-m-d'),
                        'start_time' => $serviceTime ? $serviceTime->format('H:i:s') : null,
                        'end' => $serviceDate->format('Y-m-d'),
                        'all_day' => ! $serviceTime,
                        'extended_props' => [
                            'status' => $this->getEventTypeFromStatus($log),
                            'service_time' => $this->formatServiceTime($log->service_window),
                            'service_location' => $log->service_location,
                            'agent_name' => $log->agent_name,
                            'caller_name' => $log->caller_name,
                            'caller_number' => $log->caller_phone,
                            'reason_for_call' => $log->reason_for_call,
                            'notes' => $log->notes,
                            'service_date' => $log->service_date,
                            'service_window' => $log->service_window,
                        ],
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => [
                    'events' => $serviceRequests,
                    'date_range' => [
                        'start' => $start,
                        'end' => $end,
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch calendar data',
            ], 500);
        }
    }

    /**
     * Parse service time from service window
     */
    private function parseServiceTime($serviceWindow)
    {
        if (! $serviceWindow || $serviceWindow === 'TBD' || trim($serviceWindow) === '') {
            return null;
        }

        // Check if it's already in 12-hour format (contains AM/PM)
        if (strpos($serviceWindow, 'AM') !== false || strpos($serviceWindow, 'PM') !== false) {
            try {
                return Carbon::createFromFormat('g:i A', trim($serviceWindow));
            } catch (\Exception $e) {
                // Try other formats
                try {
                    return Carbon::createFromFormat('g A', trim($serviceWindow));
                } catch (\Exception $e) {
                    return null;
                }
            }
        }

        // Check if it's in 24-hour format (HH:MM)
        $timeRegex = '/^([0-1]?[0-9]|2[0-3]):([0-5][0-9])$/';
        if (preg_match($timeRegex, $serviceWindow)) {
            try {
                return Carbon::createFromFormat('H:i', $serviceWindow);
            } catch (\Exception $e) {
                return null;
            }
        }

        return null;
    }

    /**
     * Format service time for display
     */
    private function formatServiceTime($serviceWindow)
    {
        if (! $serviceWindow || $serviceWindow === 'TBD' || trim($serviceWindow) === '') {
            return 'TBD';
        }

        // Check if it's already in 12-hour format (contains AM/PM)
        if (strpos($serviceWindow, 'AM') !== false || strpos($serviceWindow, 'PM') !== false) {
            return $serviceWindow;
        }

        // Check if it's in 24-hour format (HH:MM)
        $timeRegex = '/^([0-1]?[0-9]|2[0-3]):([0-5][0-9])$/';
        if (preg_match($timeRegex, $serviceWindow, $matches)) {
            $hours = (int) $matches[1];
            $minutes = $matches[2];
            $ampm = 'AM';

            if ($hours === 0) {
                $hours = 12;
            } elseif ($hours === 12) {
                $ampm = 'PM';
            } elseif ($hours > 12) {
                $hours -= 12;
                $ampm = 'PM';
            }

            return sprintf('%d:%s %s', $hours, $minutes, $ampm);
        }

        return $serviceWindow;
    }

    /**
     * Get event type from status for calendar styling
     */
    private function getEventTypeFromStatus($log)
    {
        if ($log->status === 'completed') {
            return 'completed';
        } elseif ($log->status === 'service-requested' || $log->call_outcome === 'scheduled-appointment') {
            return 'scheduled';
        } else {
            return 'service-request';
        }
    }

    /**
     * Map scheduled status for consistent display
     */
    /**
     * Calls of the organization resolved by the `tenant` middleware.
     */
    private function organizationCalls(): Builder
    {
        return CallLog::query()->forOrganization(app(CurrentOrganization::class)->id() ?? 0);
    }

    private function organizationPayload(): ?array
    {
        $organization = app(CurrentOrganization::class)->get();

        return $organization ? [
            'id' => $organization->ulid,
            'name' => $organization->name,
            'status' => $organization->status->value,
            'timezone' => $organization->timezone,
            'currency' => $organization->currency,
        ] : null;
    }

    private function mapScheduledStatus(CallLog $log)
    {
        $label = $log->statusLabel();

        // The mobile app already expects "Pending" for new calls.
        return $label === CallLog::STATUS_LABELS['new'] ? 'Pending' : $label;
    }
}

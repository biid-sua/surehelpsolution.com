<?php

namespace App\Http\Controllers\Api;

use App\Actions\Calls\LogCall;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCallLogRequest;
use App\Models\CallLog;
use App\Models\FcmToken;
use App\Models\User;
use App\Services\CallStatsService;
use App\Services\FcmService;
use App\Support\Audit\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AgentDashboardController extends Controller
{
    public function __construct(
        private readonly FcmService $fcmService,
        private readonly CallStatsService $stats,
    ) {}

    /**
     * Get agent dashboard KPI data
     */
    public function getKpiData(Request $request)
    {
        try {
            $user = Auth::user();

            if (! in_array($user->role, ['agent', 'admin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Agent or Admin role required.',
                ], 403);
            }

            $period = $request->get('period', 'today'); // today, weekly, monthly
            $userId = $user->role === 'admin' && $request->has('agent_id') ? $request->agent_id : $user->id;

            if (! in_array($period, CallStatsService::PERIODS, true)) {
                $period = 'today';
            }

            $kpiData = $this->stats->kpis($userId, $period);

            return response()->json([
                'success' => true,
                'data' => [
                    'kpi_data' => $kpiData,
                    'period' => $period,
                    'agent_id' => $userId,
                    'agent_name' => User::find($userId)->name ?? 'Unknown',
                ],
            ]);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch KPI data',
            ], 500);
        }
    }

    /**
     * Get agent performance analytics
     */
    public function getPerformanceData(Request $request)
    {
        try {
            $user = Auth::user();

            if (! in_array($user->role, ['agent', 'admin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Agent or Admin role required.',
                ], 403);
            }

            $userId = $user->role === 'admin' && $request->has('agent_id') ? $request->agent_id : $user->id;

            $performanceData = $this->stats->performance($userId);

            return response()->json([
                'success' => true,
                'data' => [
                    'performance_data' => $performanceData,
                    'agent_id' => $userId,
                    'agent_name' => User::find($userId)->name ?? 'Unknown',
                ],
            ]);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch performance data',
            ], 500);
        }
    }

    /**
     * Get agent call logs
     */
    public function getCallLogs(Request $request)
    {
        try {
            $user = Auth::user();

            if (! in_array($user->role, ['agent', 'admin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Agent or Admin role required.',
                ], 403);
            }

            $userId = $user->role === 'admin' && $request->has('agent_id') ? $request->agent_id : $user->id;
            $limit = $request->get('limit', 20);
            $offset = $request->get('offset', 0);
            $status = $request->get('status', 'all'); // all, new, service-requested, completed, etc.

            $query = CallLog::where('user_id', $userId);

            // Filter by status if specified
            if ($status !== 'all') {
                $query->where('status', $status);
            }

            $callLogs = $query
                ->orderBy('created_at', 'desc')
                ->offset($offset)
                ->limit($limit)
                ->get()
                ->map(function ($log) {
                    return [
                        'id' => $log->id,
                        'call_id' => $log->call_id ?? $log->id,
                        'call_date' => $log->call_date,
                        'call_time' => $log->call_time,
                        'caller_name' => $log->caller_name,
                        'caller_phone' => $log->caller_phone,
                        'caller_email' => $log->caller_email,
                        'reason_for_call' => $log->reason_for_call,
                        'call_outcome' => $log->call_outcome,
                        'agent_name' => $log->agent_name,
                        'status' => $log->status,
                        'service_request' => $log->service_request,
                        'service_date' => $log->service_date,
                        'service_window' => $log->service_window,
                        'service_location' => $log->service_location,
                        'notes' => $log->notes,
                        'created_at' => $log->created_at,
                        'updated_at' => $log->updated_at,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => [
                    'call_logs' => $callLogs,
                    'pagination' => [
                        'limit' => $limit,
                        'offset' => $offset,
                        'has_more' => $callLogs->count() === $limit,
                    ],
                    'filters' => [
                        'status' => $status,
                        'agent_id' => $userId,
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch call logs',
            ], 500);
        }
    }

    /**
     * Create new call log
     */
    public function createCallLog(StoreCallLogRequest $request, LogCall $logCall)
    {
        try {
            $callLog = $logCall->handle($request->user(), $request->validated());

            $this->notifyClientOfNewCall($callLog);

            return response()->json([
                'success' => true,
                'message' => 'Call log created successfully',
                'data' => [
                    'call_log' => [
                        'id' => $callLog->id,
                        'call_id' => $callLog->call_id,
                        'status' => $callLog->status,
                        'created_at' => $callLog->created_at,
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
                'message' => 'Failed to create call log',
            ], 500);
        }
    }

    /**
     * Update call log
     */
    public function updateCallLog(Request $request, $id)
    {
        try {
            $user = Auth::user();

            if (! in_array($user->role, ['agent', 'admin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Agent or Admin role required.',
                ], 403);
            }

            $callLog = CallLog::find($id);
            if (! $callLog) {
                return response()->json(['success' => false, 'message' => 'Call log not found'], 404);
            }

            // Own calls only (agents), and only while still allowed in that organization.
            if ($user->cannot('update', $callLog)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. You can only update your own call logs.',
                ], 403);
            }

            $request->validate([
                'call_date' => 'sometimes|date',
                'call_time' => 'sometimes',
                'caller_name' => 'sometimes|nullable|string|max:255',
                'caller_phone' => 'sometimes|nullable|string|max:20',
                'caller_email' => 'sometimes|nullable|email|max:255',
                'reason_for_call' => 'sometimes|string|max:255',
                'call_outcome' => 'sometimes|string|max:255',
                'status' => 'sometimes|in:'.implode(',', CallLog::STATUSES),
                'service_request' => 'sometimes|boolean',
                'service_date' => 'sometimes|nullable|date',
                'service_window' => 'sometimes|nullable|string|max:255',
                'service_location' => 'sometimes|nullable|string',
                'notes' => 'sometimes|nullable|string',
            ]);

            $callLog->update($request->only([
                'call_date', 'call_time', 'caller_name', 'caller_phone', 'caller_email',
                'reason_for_call', 'call_outcome', 'status', 'service_request',
                'service_date', 'service_window', 'service_location', 'notes',
            ]));

            app(Audit::class)->changes('call.updated', $callLog);

            return response()->json([
                'success' => true,
                'message' => 'Call log updated successfully',
                'data' => [
                    'call_log' => [
                        'id' => $callLog->id,
                        'call_id' => $callLog->call_id,
                        'status' => $callLog->status,
                        'updated_at' => $callLog->updated_at,
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
                'message' => 'Failed to update call log',
            ], 500);
        }
    }

    /**
     * Get clients list for call log form
     */
    public function getClientsList()
    {
        try {
            $user = Auth::user();

            if (! in_array($user->role, ['agent', 'admin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Agent or Admin role required.',
                ], 403);
            }

            $clients = User::clientsVisibleTo($user)
                ->where('is_active', true)
                ->select('id', 'name', 'email', 'phone', 'unique_id')
                ->orderBy('name')
                ->get()
                ->map(function ($client) {
                    return [
                        'id' => $client->id,
                        'unique_id' => $client->unique_id,
                        'name' => $client->name,
                        'email' => $client->email,
                        'phone' => $client->phone ?? '',
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => [
                    'clients' => $clients,
                ],
            ]);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch clients list',
            ], 500);
        }
    }

    /**
     * Send push notification when a new call log is created
     */
    private function notifyClientOfNewCall(CallLog $callLog): void
    {
        try {
            // Notify only the client the call was logged for. Matching by caller
            // email could notify an unrelated account, so it is not used.
            if (empty($callLog->client_id)) {
                return;
            }

            $tokens = FcmToken::where('user_id', $callLog->client_id)
                ->pluck('token')
                ->all();

            $this->fcmService->sendNotification(
                $tokens,
                [
                    'title' => sprintf('New Call #%s', $callLog->call_id),
                    'body' => $callLog->reason_for_call ?? 'A new call request was logged.',
                ],
                [
                    'call_id' => $callLog->call_id,
                    'status' => $callLog->status,
                    'agent_name' => $callLog->agent_name,
                    'service_request' => $callLog->service_request,
                ]
            );
        } catch (\Throwable $th) {
            Log::error('Failed to dispatch FCM notification', [
                'error' => $th->getMessage(),
                'call_id' => $callLog->call_id,
            ]);
        }
    }
}

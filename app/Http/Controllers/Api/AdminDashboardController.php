<?php

namespace App\Http\Controllers\Api;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\OutcomeCategory;
use App\Http\Controllers\Controller;
use App\Models\CallLog;
use App\Models\User;
use App\Services\Calls\CallOutcomes;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AdminDashboardController extends Controller
{
    /**
     * Get admin dashboard statistics
     */
    public function getDashboardStats()
    {
        try {
            $user = Auth::user();

            if ($user->role !== 'admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Admin role required.',
                ], 403);
            }

            $stats = $this->calculateDashboardStats();

            return response()->json([
                'success' => true,
                'data' => [
                    'stats' => $stats,
                ],
            ]);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch dashboard stats',
            ], 500);
        }
    }

    /**
     * Get all users (agents and clients)
     */
    public function getUsers(Request $request)
    {
        try {
            $user = Auth::user();

            if ($user->role !== 'admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Admin role required.',
                ], 403);
            }

            $role = $request->get('role', 'all'); // all, admin, agent, client
            $status = $request->get('status', 'all'); // all, active, inactive
            $limit = $request->get('limit', 50);
            $offset = $request->get('offset', 0);

            $query = User::query();

            // Filter by role
            if ($role !== 'all') {
                $query->where('role', $role);
            }

            // Filter by status
            if ($status !== 'all') {
                $query->where('is_active', $status === 'active');
            }

            $users = $query
                ->orderBy('created_at', 'desc')
                ->offset($offset)
                ->limit($limit)
                ->get()
                ->map(function ($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'phone' => $user->phone,
                        'role' => $user->role,
                        'unique_id' => $user->unique_id,
                        'is_active' => $user->is_active,
                        'created_at' => $user->created_at,
                        'updated_at' => $user->updated_at,
                    ];
                });

            // Get total count for pagination
            $totalCount = $query->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'users' => $users,
                    'pagination' => [
                        'limit' => $limit,
                        'offset' => $offset,
                        'total' => $totalCount,
                        'has_more' => ($offset + $limit) < $totalCount,
                    ],
                    'filters' => [
                        'role' => $role,
                        'status' => $status,
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch users',
            ], 500);
        }
    }

    /**
     * Create new user
     */
    public function createUser(Request $request)
    {
        try {
            $user = Auth::user();

            if ($user->role !== 'admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Admin role required.',
                ], 403);
            }

            $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|email|unique:users,email|max:255',
                'phone' => 'nullable|string|max:20',
                'role' => 'required|in:admin,agent,client',
                'password' => 'required|string|min:8',
                'is_active' => 'nullable|boolean',
            ]);

            $mustChangePassword = in_array($request->role, ['agent', 'client'], true);

            $newUser = DB::transaction(function () use ($request, $mustChangePassword) {
                $newUser = User::create([
                    'name' => $request->name,
                    'email' => $request->email,
                    'phone' => $request->phone,
                    'role' => $request->role,
                    'password' => Hash::make($request->password),
                    'is_active' => $request->boolean('is_active', true),
                    'must_change_password' => $mustChangePassword,
                ]);

                // Client → own organization; agent → assignments (docs/decisions.md D1, D3).
                app(ProvisionUserTenancy::class)->handle($newUser);

                return $newUser;
            });

            return response()->json([
                'success' => true,
                'message' => 'User created successfully',
                'data' => [
                    'user' => [
                        'id' => $newUser->id,
                        'name' => $newUser->name,
                        'email' => $newUser->email,
                        'phone' => $newUser->phone,
                        'role' => $newUser->role,
                        'unique_id' => $newUser->unique_id,
                        'is_active' => $newUser->is_active,
                        'created_at' => $newUser->created_at,
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
                'message' => 'Failed to create user',
            ], 500);
        }
    }

    /**
     * Update user
     */
    public function updateUser(Request $request, $id)
    {
        try {
            $user = Auth::user();

            if ($user->role !== 'admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Admin role required.',
                ], 403);
            }

            $targetUser = User::findOrFail($id);

            $request->validate([
                'name' => 'sometimes|string|max:255',
                'email' => 'sometimes|email|unique:users,email,'.$id.'|max:255',
                'phone' => 'sometimes|nullable|string|max:20',
                'role' => 'sometimes|in:admin,agent,client',
                'password' => 'sometimes|string|min:8',
                'is_active' => 'sometimes|boolean',
            ]);

            $updateData = $request->only(['name', 'email', 'phone', 'role', 'is_active']);

            if ($request->has('password')) {
                $updateData['password'] = Hash::make($request->password);

                if (in_array($targetUser->role, ['agent', 'client'], true)) {
                    $updateData['must_change_password'] = true;
                }
            }

            $previousRole = $targetUser->role;
            $targetUser->update($updateData);

            if ($targetUser->role !== $previousRole) {
                app(ProvisionUserTenancy::class)->handle($targetUser);
            }

            if (! $targetUser->is_active) {
                $targetUser->tokens()->delete();
            }

            return response()->json([
                'success' => true,
                'message' => 'User updated successfully',
                'data' => [
                    'user' => [
                        'id' => $targetUser->id,
                        'name' => $targetUser->name,
                        'email' => $targetUser->email,
                        'phone' => $targetUser->phone,
                        'role' => $targetUser->role,
                        'unique_id' => $targetUser->unique_id,
                        'is_active' => $targetUser->is_active,
                        'updated_at' => $targetUser->updated_at,
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
                'message' => 'Failed to update user',
            ], 500);
        }
    }

    /**
     * Delete user
     */
    public function deleteUser($id)
    {
        try {
            $user = Auth::user();

            if ($user->role !== 'admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Admin role required.',
                ], 403);
            }

            // Prevent admin from deleting themselves
            if ($id == $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot delete your own account',
                ], 400);
            }

            $targetUser = User::findOrFail($id);
            $targetUser->delete();

            return response()->json([
                'success' => true,
                'message' => 'User deleted successfully',
            ]);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete user',
            ], 500);
        }
    }

    /**
     * Get all call logs (admin view)
     */
    public function getAllCallLogs(Request $request)
    {
        try {
            $user = Auth::user();

            if ($user->role !== 'admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Admin role required.',
                ], 403);
            }

            $agentId = $request->get('agent_id', 'all');
            $status = $request->get('status', 'all');
            $dateFrom = $request->get('date_from');
            $dateTo = $request->get('date_to');
            $limit = $request->get('limit', 50);
            $offset = $request->get('offset', 0);

            $query = CallLog::with('user');

            // Filter by agent
            if ($agentId !== 'all') {
                $query->where('user_id', $agentId);
            }

            // Filter by status
            if ($status !== 'all') {
                $query->where('status', $status);
            }

            // Filter by date range
            if ($dateFrom) {
                $query->whereDate('created_at', '>=', $dateFrom);
            }
            if ($dateTo) {
                $query->whereDate('created_at', '<=', $dateTo);
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
                        'agent_id' => $log->user_id,
                        'agent_unique_id' => $log->user->unique_id ?? null,
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

            // Get total count for pagination
            $totalCount = $query->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'call_logs' => $callLogs,
                    'pagination' => [
                        'limit' => $limit,
                        'offset' => $offset,
                        'total' => $totalCount,
                        'has_more' => ($offset + $limit) < $totalCount,
                    ],
                    'filters' => [
                        'agent_id' => $agentId,
                        'status' => $status,
                        'date_from' => $dateFrom,
                        'date_to' => $dateTo,
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
     * Get system analytics
     */
    public function getSystemAnalytics(Request $request)
    {
        try {
            $user = Auth::user();

            if ($user->role !== 'admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Admin role required.',
                ], 403);
            }

            $period = $request->get('period', '7days'); // 7days, 30days, 90days

            $analytics = $this->calculateSystemAnalytics($period);

            return response()->json([
                'success' => true,
                'data' => [
                    'analytics' => $analytics,
                    'period' => $period,
                ],
            ]);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch system analytics',
            ], 500);
        }
    }

    /**
     * Get agent performance summary
     */
    public function getAgentPerformance()
    {
        try {
            $user = Auth::user();

            if ($user->role !== 'admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Admin role required.',
                ], 403);
            }

            $agents = User::where('role', 'agent')
                ->where('is_active', true)
                ->get()
                ->map(function ($agent) {
                    $now = Carbon::now();
                    $thisWeek = $now->copy()->startOfWeek();

                    $totalCalls = CallLog::where('user_id', $agent->id)->count();
                    $callsThisWeek = CallLog::where('user_id', $agent->id)
                        ->whereDate('created_at', '>=', $thisWeek)
                        ->count();
                    $serviceRequests = CallLog::where('user_id', $agent->id)
                        ->where('service_request', true)
                        ->count();

                    return [
                        'id' => $agent->id,
                        'name' => $agent->name,
                        'unique_id' => $agent->unique_id,
                        'email' => $agent->email,
                        'total_calls' => $totalCalls,
                        'calls_this_week' => $callsThisWeek,
                        'service_requests' => $serviceRequests,
                        'conversion_rate' => $totalCalls > 0 ? round(($serviceRequests / $totalCalls) * 100, 1) : 0,
                        'created_at' => $agent->created_at,
                    ];
                })
                ->sortByDesc('calls_this_week')
                ->values();

            return response()->json([
                'success' => true,
                'data' => [
                    'agents' => $agents,
                ],
            ]);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch agent performance',
            ], 500);
        }
    }

    /**
     * Calculate dashboard statistics for admin view
     */
    private function calculateDashboardStats()
    {
        $now = Carbon::now();
        $today = $now->startOfDay();
        $yesterday = $now->copy()->subDay()->startOfDay();

        // Total Agents
        $totalAgents = User::where('role', 'agent')->where('is_active', true)->count();
        $totalAgentsYesterday = User::where('role', 'agent')
            ->where('is_active', true)
            ->where('created_at', '<', $yesterday)
            ->count();

        // Active Clients
        $activeClients = User::where('role', 'client')->where('is_active', true)->count();
        $activeClientsYesterday = User::where('role', 'client')
            ->where('is_active', true)
            ->where('created_at', '<', $yesterday)
            ->count();

        // Calls Today
        $callsToday = CallLog::whereDate('created_at', $today)->count();
        $callsYesterday = CallLog::whereDate('created_at', $yesterday)->count();

        // Success Rate (calls with successful outcomes)
        $successfulCallsToday = CallLog::whereDate('created_at', $today)
            ->whereIn('call_outcome', app(CallOutcomes::class)->keys(null, OutcomeCategory::Booked, OutcomeCategory::Information))
            ->count();

        $successfulCallsYesterday = CallLog::whereDate('created_at', $yesterday)
            ->whereIn('call_outcome', app(CallOutcomes::class)->keys(null, OutcomeCategory::Booked, OutcomeCategory::Information))
            ->count();

        $successRateToday = $callsToday > 0 ? round(($successfulCallsToday / $callsToday) * 100, 1) : 0;
        $successRateYesterday = $callsYesterday > 0 ? round(($successfulCallsYesterday / $callsYesterday) * 100, 1) : 0;

        // Calculate percentage changes
        $agentsChange = $this->calculatePercentageChange($totalAgents, $totalAgentsYesterday);
        $clientsChange = $this->calculatePercentageChange($activeClients, $activeClientsYesterday);
        $callsChange = $this->calculatePercentageChange($callsToday, $callsYesterday);
        $successRateChange = $this->calculatePercentageChange($successRateToday, $successRateYesterday);

        return [
            'total_agents' => [
                'value' => $totalAgents,
                'change' => $agentsChange,
                'formatted' => number_format($totalAgents),
            ],
            'active_clients' => [
                'value' => $activeClients,
                'change' => $clientsChange,
                'formatted' => number_format($activeClients),
            ],
            'calls_today' => [
                'value' => $callsToday,
                'change' => $callsChange,
                'formatted' => number_format($callsToday),
            ],
            'success_rate' => [
                'value' => $successRateToday,
                'change' => $successRateChange,
                'formatted' => $successRateToday.'%',
            ],
        ];
    }

    /**
     * Calculate system analytics
     */
    private function calculateSystemAnalytics($period)
    {
        $now = Carbon::now();
        $days = match ($period) {
            '7days' => 7,
            '30days' => 30,
            '90days' => 90,
            default => 7
        };

        $startDate = $now->copy()->subDays($days)->startOfDay();

        // Daily call trends
        $dailyCalls = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = $now->copy()->subDays($i)->startOfDay();
            $dailyCalls[] = [
                'date' => $date->format('M d'),
                'calls' => CallLog::whereDate('created_at', $date)->count(),
                'service_requests' => CallLog::whereDate('created_at', $date)->where('service_request', true)->count(),
            ];
        }

        // Call outcomes distribution
        $callOutcomes = CallLog::whereDate('created_at', '>=', $startDate)
            ->selectRaw('call_outcome, COUNT(*) as count')
            ->groupBy('call_outcome')
            ->pluck('count', 'call_outcome')
            ->toArray();

        // Agent performance (top 10)
        $agentPerformance = CallLog::whereDate('created_at', '>=', $startDate)
            ->selectRaw('user_id, agent_name, COUNT(*) as total_calls, SUM(CASE WHEN service_request = 1 THEN 1 ELSE 0 END) as service_requests')
            ->groupBy('user_id', 'agent_name')
            ->orderByDesc('total_calls')
            ->limit(10)
            ->get()
            ->map(function ($item) {
                return [
                    'agent_name' => $item->agent_name,
                    'total_calls' => $item->total_calls,
                    'service_requests' => $item->service_requests,
                    'conversion_rate' => $item->total_calls > 0 ? round(($item->service_requests / $item->total_calls) * 100, 1) : 0,
                ];
            });

        return [
            'daily_trends' => $dailyCalls,
            'call_outcomes' => $callOutcomes,
            'agent_performance' => $agentPerformance,
        ];
    }

    /**
     * Calculate percentage change between two values
     */
    private function calculatePercentageChange($current, $previous)
    {
        if ($previous == 0) {
            return $current > 0 ? 100 : 0;
        }

        return round((($current - $previous) / $previous) * 100);
    }
}

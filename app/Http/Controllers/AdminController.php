<?php

namespace App\Http\Controllers;

use App\Actions\Calls\LogCall;
use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\OutcomeCategory;
use App\Http\Requests\StoreCallLogRequest;
use App\Models\CallLog;
use App\Models\ContactSubmission;
use App\Models\User;
use App\Services\Calls\CallOutcomes;
use App\Services\CallStatsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AdminController extends Controller
{
    /**
     * Display the admin dashboard.
     */
    public function dashboard()
    {
        // Calculate dynamic stats
        $stats = $this->calculateDashboardStats();

        $recentContactSubmissions = ContactSubmission::orderByDesc('created_at')->limit(5)->get();

        $recentCalls = CallLog::with('user:id,name')->latest()->limit(8)->get();
        $clientNames = User::whereIn('id', $recentCalls->pluck('client_id')->filter()->unique())
            ->pluck('name', 'id');

        return view('admin.dashboard', compact('stats', 'recentContactSubmissions', 'recentCalls', 'clientNames'));
    }

    /**
     * Display the agent dashboard.
     */
    public function agentDashboard(CallStatsService $stats)
    {
        $callLogs = CallLog::where('user_id', Auth::id())
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        $kpiData = $stats->kpis(Auth::id(), 'today');
        $performanceData = $stats->performance(Auth::id());

        return view('admin.agent-dashboard', compact('callLogs', 'kpiData', 'performanceData'));
    }

    /**
     * Calculate percentage change between two values.
     */
    private function calculatePercentageChange($current, $previous)
    {
        return app(CallStatsService::class)->percentageChange($current, $previous);
    }

    /**
     * Calculate dashboard statistics for admin view.
     */
    private function calculateDashboardStats()
    {
        $today = now()->startOfDay();
        $yesterday = now()->subDay()->startOfDay();

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

    // Legacy client dashboard URL: the business portal now lives at /app.
    public function clientDashboard()
    {
        return Auth::user()->isAdmin()
            ? redirect()->route('admin.organizations.index')
            : redirect()->route('app.dashboard');
    }

    /**
     * Store a new call log entry.
     */
    public function storeCallLog(StoreCallLogRequest $request, LogCall $logCall)
    {
        try {
            $callLog = $logCall->handle($request->user(), $request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Call log saved successfully!',
                'call_log' => $callLog,
                'call_id' => $callLog->call_id,
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
                'message' => 'Failed to save call log. Please try again.',
            ], 500);
        }
    }

    /**
     * Get call logs for the authenticated user.
     */
    public function getCallLogs()
    {
        $callLogs = CallLog::where('user_id', Auth::id())
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'call_logs' => $callLogs,
        ]);
    }

    /**
     * Download the authenticated agent's call logs as CSV.
     */
    public function exportCallLogs()
    {
        $columns = [
            'call_id' => 'Call ID',
            'created_at' => 'Logged At (UTC)',
            'caller_name' => 'Caller Name',
            'caller_phone' => 'Caller Phone',
            'caller_email' => 'Caller Email',
            'reason_for_call' => 'Reason',
            'call_outcome' => 'Outcome',
            'status' => 'Status',
            'service_date' => 'Service Date',
            'service_window' => 'Service Window',
            'service_location' => 'Service Location',
            'notes' => 'Notes',
        ];

        $filename = 'call-logs-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($columns) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_values($columns));

            CallLog::where('user_id', Auth::id())
                ->orderByDesc('created_at')
                ->chunk(500, function ($logs) use ($out, $columns) {
                    foreach ($logs as $log) {
                        fputcsv($out, array_map(function ($column) use ($log) {
                            $value = $log->{$column};
                            if ($value instanceof \DateTimeInterface) {
                                $value = $column === 'service_date' ? $value->format('Y-m-d') : $value->format('Y-m-d H:i');
                            }

                            // Prevent spreadsheet formula injection from caller-supplied text.
                            $value = (string) $value;

                            return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
                        }, array_keys($columns)));
                    }
                });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Get KPI data for a specific period.
     */
    public function getKpiData($period, CallStatsService $stats)
    {
        if (! in_array($period, CallStatsService::PERIODS, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid period',
            ], 400);
        }

        return response()->json([
            'success' => true,
            'kpi_data' => $stats->kpis(Auth::id(), $period),
            'performance_data' => $stats->performance(Auth::id()),
        ]);
    }

    /**
     * Get list of clients for the call log form.
     */
    public function getClientsList()
    {
        try {
            $outcomes = app(CallOutcomes::class);
            $clients = User::clientsVisibleTo(Auth::user())
                ->select('id', 'name', 'email', 'phone', 'unique_id')
                ->orderBy('name')
                ->get()
                ->map(function ($user) use ($outcomes) {
                    return [
                        'id' => $user->id,
                        'unique_id' => $user->unique_id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'phone' => $user->phone ?? '',
                        'call_outcomes' => $outcomes->menu($user->primaryOrganization()),
                    ];
                });

            return response()->json([
                'success' => true,
                'clients' => $clients,
            ]);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch clients. Please try again.',
            ], 500);
        }
    }

    /**
     * Store a new user.
     */
    public function storeUser(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'email' => 'required|email|unique:users,email|max:255',
                'phone' => 'nullable|string|max:20',
                'role' => 'required|in:admin,agent,client',
                'password' => 'required|string|min:8|confirmed',
                'is_active' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                \Log::error('Validation failed:', $validator->errors()->toArray());

                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Handle is_active checkbox value
            $isActive = true; // Default value
            if ($request->has('is_active')) {
                $isActive = $request->boolean('is_active');
            }

            $mustChangePassword = in_array($request->role, ['agent', 'client'], true);

            $user = DB::transaction(function () use ($request, $isActive, $mustChangePassword) {
                $user = User::create([
                    'name' => $request->name,
                    'email' => $request->email,
                    'phone' => $request->phone,
                    'role' => $request->role,
                    'password' => Hash::make($request->password),
                    'is_active' => $isActive,
                    'must_change_password' => $mustChangePassword,
                ]);

                // Client → own organization; agent → assignments (docs/decisions.md D1, D3).
                app(ProvisionUserTenancy::class)->handle($user);

                return $user;
            });

            \Log::info('User created successfully:', ['user_id' => $user->id, 'email' => $user->email]);

            $message = $mustChangePassword
                ? 'User created successfully! Share the default password with them — they must change it on first login.'
                : 'User created successfully!';

            return response()->json([
                'success' => true,
                'message' => $message,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'is_active' => $user->is_active,
                    'must_change_password' => $user->must_change_password,
                ],
            ]);

        } catch (\Exception $e) {
            // Never log the request body here: it contains the new user's password.
            \Log::error('Failed to create user:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->except(['password', 'password_confirmation']),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create user. Please try again.',
            ], 500);
        }
    }
}

<?php

use App\Http\Controllers\Agent\TrainingFileController;
use App\Http\Controllers\Api\AdminDashboardController;
use App\Http\Controllers\Api\AgentCompanyController;
use App\Http\Controllers\Api\AgentDashboardController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientBusinessController;
use App\Http\Controllers\Api\ClientDashboardController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\DutyScheduleController;
use App\Http\Controllers\Api\EscalationController;
use App\Http\Controllers\Api\InboxController;
use App\Http\Controllers\Api\KnowledgeController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\SocialPostController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TrainingController;
use App\Http\Controllers\Chat\SiteToolsController;
use App\Http\Controllers\Chat\WidgetController;
use App\Http\Controllers\Webhooks\CalendarWebhookController;
use App\Http\Controllers\Webhooks\MetaWebhookController;
use Illuminate\Support\Facades\Route;

// Public API routes (no authentication required)
// Website chat widget (public, D37): each business's widget key, allowed sites and rate limits are checked in the controller.
Route::get('/chat/widget.js', [WidgetController::class, 'script'])->name('chat.widget-js');
Route::prefix('chat/{key}')->middleware('throttle:chat')->where(['key' => 'shw_[a-z0-9]{32}'])->group(function () {
    Route::get('/config', [WidgetController::class, 'config']);
    Route::get('/messages', [WidgetController::class, 'poll']);
    Route::post('/messages', [WidgetController::class, 'send']);
    // Online booking and the contact form in the same snippet (spec §41B, D44).
    Route::get('/booking/services', [SiteToolsController::class, 'services']);
    Route::get('/booking/slots', [SiteToolsController::class, 'slots']);
    Route::post('/booking', [SiteToolsController::class, 'book']);
    Route::post('/lead', [SiteToolsController::class, 'lead']);
    Route::options('/{any?}', [WidgetController::class, 'options'])->where('any', '.*');
});

// Messenger and Instagram messages (signed by Meta with the app secret, D37).
Route::get('/webhooks/meta', [MetaWebhookController::class, 'verify'])->middleware('throttle:60,1');
Route::post('/webhooks/meta', [MetaWebhookController::class, 'receive'])->middleware('throttle:600,1')->name('webhooks.meta');

// Calendar change notifications (no auth: verified by a per-connection secret, docs/calendar-sync.md).
Route::prefix('webhooks/calendar')->name('webhooks.calendar.')->middleware('throttle:240,1')->group(function () {
    Route::post('/google', [CalendarWebhookController::class, 'google'])->name('google');
    Route::post('/microsoft', [CalendarWebhookController::class, 'microsoft'])->name('microsoft');
});

Route::prefix('v1')->middleware('throttle:api')->group(function () {
    // Authentication routes
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
});

// Protected API routes (authentication required, active accounts only)
Route::prefix('v1')->middleware(['auth:sanctum', 'api.active', 'throttle:api'])->group(function () {
    // User profile and authentication
    Route::get('/user', [AuthController::class, 'me']);
    Route::post('/refresh-token', [AuthController::class, 'refresh']);

    // Signed-in devices (docs/api.md)
    Route::get('/devices', [DeviceController::class, 'index']);
    Route::delete('/devices', [DeviceController::class, 'destroyOthers']);
    Route::delete('/devices/{id}', [DeviceController::class, 'destroy'])->whereNumber('id');

    // Client Dashboard Routes
    Route::prefix('client')->middleware(['role:client', 'tenant'])->group(function () {
        Route::middleware('can:calls.view')->group(function () {
            Route::get('/dashboard/summary', [ClientDashboardController::class, 'getDashboardSummary']);
            Route::get('/call-history', [ClientDashboardController::class, 'getCallHistory']);
            Route::get('/service-requests', [ClientDashboardController::class, 'getServiceRequests']);
            Route::get('/calendar', [ClientDashboardController::class, 'getCalendarData']);
        });
        Route::get('/business', [ClientBusinessController::class, 'show'])->middleware('can:organization.view');
        Route::get('/services', [ClientBusinessController::class, 'services'])->middleware('can:organization.view');
        Route::get('/knowledge', [KnowledgeController::class, 'index'])->middleware('can:knowledge_base.view');
        Route::get('/rules', [KnowledgeController::class, 'rules'])->middleware('can:organization.view');
        Route::middleware('can:customers.view')->group(function () {
            Route::get('/customers', [CustomerController::class, 'index']);
            Route::get('/customers/{ulid}', [CustomerController::class, 'show']);
        });
        Route::get('/inbox/conversations', [InboxController::class, 'index'])->middleware('can:messages.view');
        Route::get('/inbox/conversations/{ulid}', [InboxController::class, 'show'])->middleware('can:messages.view');
        Route::post('/inbox/conversations/{ulid}/reply', [InboxController::class, 'reply'])->middleware(['can:messages.send', 'idempotent']);
        Route::get('/social/posts', [SocialPostController::class, 'index'])->middleware('can:social.view');
        Route::post('/social/posts/{ulid}/approve', [SocialPostController::class, 'approve'])->middleware('can:social.manage');
        Route::post('/social/posts/{ulid}/request-changes', [SocialPostController::class, 'requestChanges'])->middleware('can:social.manage');
        Route::get('/tasks', [TaskController::class, 'index'])->middleware('can:tasks.view');
        Route::post('/tasks', [TaskController::class, 'store'])->middleware(['can:tasks.create', 'idempotent']);
        Route::get('/tasks/{ulid}', [TaskController::class, 'show'])->middleware('can:tasks.view');
        Route::patch('/tasks/{ulid}', [TaskController::class, 'update'])->middleware('can:tasks.update');
        Route::middleware('can:appointments.view')->group(function () {
            Route::get('/appointments', [AppointmentController::class, 'index']);
            Route::get('/availability', [AppointmentController::class, 'availability']);
            Route::get('/appointments/{ulid}', [AppointmentController::class, 'show']);
        });
        Route::post('/appointments', [AppointmentController::class, 'store'])->middleware(['can:appointments.create', 'idempotent']);
        Route::patch('/appointments/{ulid}', [AppointmentController::class, 'update'])->middleware('can:appointments.view');
        Route::get('/escalations', [EscalationController::class, 'index'])->middleware('can:escalations.view');
        Route::get('/escalations/{ulid}', [EscalationController::class, 'show'])->middleware('can:escalations.view');
        Route::middleware('can:escalations.resolve')->group(function () {
            Route::post('/escalations/{ulid}/acknowledge', [EscalationController::class, 'acknowledge']);
            Route::post('/escalations/{ulid}/assign', [EscalationController::class, 'assign']);
            Route::post('/escalations/{ulid}/resolve', [EscalationController::class, 'resolve']);
        });
        Route::get('/profile', [ClientDashboardController::class, 'getProfile']);
        Route::put('/profile', [ClientDashboardController::class, 'updateProfile']);
    });

    // Agent Dashboard Routes
    Route::prefix('agent')->middleware('role:agent,admin')->group(function () {
        Route::get('/dashboard/kpi', [AgentDashboardController::class, 'getKpiData']);
        Route::get('/dashboard/performance', [AgentDashboardController::class, 'getPerformanceData']);
        Route::get('/call-logs', [AgentDashboardController::class, 'getCallLogs']);
        Route::post('/call-logs', [AgentDashboardController::class, 'createCallLog'])->middleware(['can:calls.create', 'idempotent']);
        Route::put('/call-logs/{id}', [AgentDashboardController::class, 'updateCallLog']);
        Route::get('/clients', [AgentDashboardController::class, 'getClientsList']);
        // Assigned companies (D40): the same check as the web portal; unassigned = 404.
        Route::get('/companies', [AgentCompanyController::class, 'index']);
        Route::get('/companies/{company}', [AgentCompanyController::class, 'show']);
        Route::get('/companies/{company}/customers', [AgentCompanyController::class, 'customers']);
        Route::get('/companies/{company}/customers/{customer}', [AgentCompanyController::class, 'customer']);
        Route::get('/companies/{company}/appointments', [AgentCompanyController::class, 'appointments']);
        Route::get('/companies/{company}/calls', [AgentCompanyController::class, 'calls']);
        Route::get('/companies/{company}/conversations', [AgentCompanyController::class, 'conversations']);
        // Agent University (docs/agent-university.md): same services and rules as the web portal.
        Route::prefix('training')->group(function () {
            Route::get('/', [TrainingController::class, 'index']);
            Route::get('/certificates', [TrainingController::class, 'certificates']);
            Route::get('/courses/{course}', [TrainingController::class, 'show']);
            Route::post('/courses/{course}/start', [TrainingController::class, 'start']);
            Route::get('/courses/{course}/lessons/{lesson}', [TrainingController::class, 'lesson']);
            Route::post('/courses/{course}/lessons/{lesson}/complete', [TrainingController::class, 'complete']);
            Route::post('/courses/{course}/lessons/{lesson}/attempts', [TrainingController::class, 'startAttempt'])->middleware('throttle:30,1');
            Route::post('/attempts/{attempt}/submit', [TrainingController::class, 'submitAttempt'])->middleware('throttle:30,1');
            Route::get('/courses/{course}/v{version}/files/{lesson}', [TrainingFileController::class, 'lesson'])->whereNumber('version');
        });
    });

    // Admin Dashboard Routes
    Route::prefix('admin')->middleware('role:admin')->group(function () {
        // Dashboard stats and analytics
        Route::get('/dashboard/stats', [AdminDashboardController::class, 'getDashboardStats'])->middleware('can:dashboard.view');
        Route::get('/analytics', [AdminDashboardController::class, 'getSystemAnalytics'])->middleware('can:reports.view');
        Route::get('/agent-performance', [AdminDashboardController::class, 'getAgentPerformance'])->middleware('can:reports.view');

        // User management
        Route::get('/users', [AdminDashboardController::class, 'getUsers'])->middleware('can:users.view');
        Route::post('/users', [AdminDashboardController::class, 'createUser'])->middleware(['can:users.create', 'idempotent']);
        Route::put('/users/{id}', [AdminDashboardController::class, 'updateUser'])->middleware('can:users.update');
        Route::delete('/users/{id}', [AdminDashboardController::class, 'deleteUser'])->middleware('can:users.delete');

        // Call logs management
        Route::get('/call-logs', [AdminDashboardController::class, 'getAllCallLogs'])->middleware('can:calls.view');

        // Duty schedule management
        Route::get('/duty-schedules', [DutyScheduleController::class, 'getSchedules'])->middleware('can:users.view');
        Route::get('/duty-schedules/calendar', [DutyScheduleController::class, 'getCalendarData'])->middleware('can:users.view');
        Route::middleware('can:users.update')->group(function () {
            Route::post('/duty-schedules', [DutyScheduleController::class, 'createSchedule']);
            Route::put('/duty-schedules/{id}', [DutyScheduleController::class, 'updateSchedule']);
            Route::delete('/duty-schedules/{id}', [DutyScheduleController::class, 'deleteSchedule']);
            Route::post('/duty-schedules/check-conflicts', [DutyScheduleController::class, 'checkConflicts']);
        });
    });

    // Shared routes for agents and admins
    Route::middleware('role:agent,admin')->group(function () {
        Route::get('/duty-schedules', [DutyScheduleController::class, 'getSchedules']);
        Route::get('/duty-schedules/calendar', [DutyScheduleController::class, 'getCalendarData']);
    });

    Route::prefix('notifications')->group(function () {
        Route::post('/device-token', [NotificationController::class, 'storeDeviceToken']);
        Route::post('/test', [NotificationController::class, 'sendTestNotification'])->middleware('role:admin');
    });
});

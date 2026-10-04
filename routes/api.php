<?php

use App\Http\Controllers\Api\AdminDashboardController;
use App\Http\Controllers\Api\AgentDashboardController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientBusinessController;
use App\Http\Controllers\Api\ClientDashboardController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\DutyScheduleController;
use App\Http\Controllers\Api\EscalationController;
use App\Http\Controllers\Api\KnowledgeController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Webhooks\CalendarWebhookController;
use Illuminate\Support\Facades\Route;

// Public API routes (no authentication required)
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
        Route::get('/tasks', [TaskController::class, 'index'])->middleware('can:tasks.view');
        Route::post('/tasks', [TaskController::class, 'store'])->middleware('can:tasks.create');
        Route::get('/tasks/{ulid}', [TaskController::class, 'show'])->middleware('can:tasks.view');
        Route::patch('/tasks/{ulid}', [TaskController::class, 'update'])->middleware('can:tasks.update');
        Route::middleware('can:appointments.view')->group(function () {
            Route::get('/appointments', [AppointmentController::class, 'index']);
            Route::get('/availability', [AppointmentController::class, 'availability']);
            Route::get('/appointments/{ulid}', [AppointmentController::class, 'show']);
        });
        Route::post('/appointments', [AppointmentController::class, 'store'])->middleware('can:appointments.create');
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
        Route::post('/call-logs', [AgentDashboardController::class, 'createCallLog'])->middleware('can:calls.create');
        Route::put('/call-logs/{id}', [AgentDashboardController::class, 'updateCallLog']);
        Route::get('/clients', [AgentDashboardController::class, 'getClientsList']);
    });

    // Admin Dashboard Routes
    Route::prefix('admin')->middleware('role:admin')->group(function () {
        // Dashboard stats and analytics
        Route::get('/dashboard/stats', [AdminDashboardController::class, 'getDashboardStats']);
        Route::get('/analytics', [AdminDashboardController::class, 'getSystemAnalytics']);
        Route::get('/agent-performance', [AdminDashboardController::class, 'getAgentPerformance']);

        // User management
        Route::get('/users', [AdminDashboardController::class, 'getUsers']);
        Route::post('/users', [AdminDashboardController::class, 'createUser']);
        Route::put('/users/{id}', [AdminDashboardController::class, 'updateUser']);
        Route::delete('/users/{id}', [AdminDashboardController::class, 'deleteUser']);

        // Call logs management
        Route::get('/call-logs', [AdminDashboardController::class, 'getAllCallLogs']);

        // Duty schedule management
        Route::get('/duty-schedules', [DutyScheduleController::class, 'getSchedules']);
        Route::get('/duty-schedules/calendar', [DutyScheduleController::class, 'getCalendarData']);
        Route::post('/duty-schedules', [DutyScheduleController::class, 'createSchedule']);
        Route::put('/duty-schedules/{id}', [DutyScheduleController::class, 'updateSchedule']);
        Route::delete('/duty-schedules/{id}', [DutyScheduleController::class, 'deleteSchedule']);
        Route::post('/duty-schedules/check-conflicts', [DutyScheduleController::class, 'checkConflicts']);
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

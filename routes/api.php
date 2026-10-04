<?php

use App\Http\Controllers\Api\AdminDashboardController;
use App\Http\Controllers\Api\AgentDashboardController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClientDashboardController;
use App\Http\Controllers\Api\DutyScheduleController;
use App\Http\Controllers\Api\NotificationController;
use Illuminate\Support\Facades\Route;

// Public API routes (no authentication required)
Route::prefix('v1')->group(function () {
    // Authentication routes
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
});

// Protected API routes (authentication required, active accounts only)
Route::prefix('v1')->middleware(['auth:sanctum', 'api.active'])->group(function () {
    // User profile and authentication
    Route::get('/user', [AuthController::class, 'me']);
    Route::post('/refresh-token', [AuthController::class, 'refresh']);

    // Client Dashboard Routes
    Route::prefix('client')->middleware(['role:client', 'tenant'])->group(function () {
        Route::middleware('can:calls.view')->group(function () {
            Route::get('/dashboard/summary', [ClientDashboardController::class, 'getDashboardSummary']);
            Route::get('/call-history', [ClientDashboardController::class, 'getCallHistory']);
            Route::get('/service-requests', [ClientDashboardController::class, 'getServiceRequests']);
            Route::get('/calendar', [ClientDashboardController::class, 'getCalendarData']);
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

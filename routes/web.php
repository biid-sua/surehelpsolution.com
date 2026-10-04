<?php

use App\Http\Controllers\Admin\AgentDutyScheduleController;
use App\Http\Controllers\Admin\ContactSubmissionController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PasswordChangeController;
use Illuminate\Support\Facades\Route;

// Landing page
Route::get('/', [HomeController::class, 'index'])->name('home');
Route::post('/contact', [ContactController::class, 'store'])->name('contact.store');

// Legal pages
Route::get('/terms-of-use', [HomeController::class, 'termsOfUse'])->name('legal.terms-of-use');
Route::get('/privacy-policy', [HomeController::class, 'privacyPolicy'])->name('legal.privacy-policy');
Route::get('/data-processing-addendum', [HomeController::class, 'dataProcessingAddendum'])->name('legal.data-processing-addendum');
Route::get('/business-associate-agreement', [HomeController::class, 'businessAssociateAgreement'])->name('legal.business-associate-agreement');
Route::get('/data-security', [HomeController::class, 'dataSecurity'])->name('legal.data-security');
Route::get('/cookie-notice', [HomeController::class, 'cookieNotice'])->name('legal.cookie-notice');
Route::get('/faq', [HomeController::class, 'faq'])->name('legal.faq');

// Authentication routes
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('auth.login');
Route::match(['get', 'post'], '/logout', [AuthController::class, 'logout'])->name('auth.logout');

// Password change (agent/client first-login flow — no force middleware here)
Route::prefix('admin')->middleware('auth.home')->group(function () {
    Route::get('/change-password', [PasswordChangeController::class, 'show'])->name('password.change');
    Route::post('/change-password', [PasswordChangeController::class, 'update'])->name('password.change.update');
});

// Admin routes (redirect guests to home instead of default login)
Route::prefix('admin')->middleware(['auth.home', 'force.password.change'])->group(function () {
    Route::get('/dashboard', [AdminController::class, 'dashboard'])->middleware('role:admin')->name('admin.dashboard');
    Route::get('/agent-dashboard', [AdminController::class, 'agentDashboard'])->middleware('role:agent,admin')->name('admin.agent-dashboard');
    Route::get('/client-dashboard', [AdminController::class, 'clientDashboard'])->middleware(['role:client,admin', 'tenant', 'can:calls.view'])->name('admin.client-dashboard');

    // Agent workspace routes (call logs, KPIs, client picker)
    Route::middleware('role:agent,admin')->group(function () {
        Route::post('/call-logs', [AdminController::class, 'storeCallLog'])->middleware('can:calls.create')->name('admin.call-logs.store');
        Route::get('/call-logs', [AdminController::class, 'getCallLogs'])->name('admin.call-logs.index');
        Route::get('/call-logs/export', [AdminController::class, 'exportCallLogs'])->name('admin.call-logs.export');
        Route::get('/kpi-data/{period}', [AdminController::class, 'getKpiData'])->name('admin.kpi-data');
        Route::get('/clients/list', [AdminController::class, 'getClientsList'])->name('admin.clients.list');
        Route::get('/duty-schedules/calendar-data', [AgentDutyScheduleController::class, 'getCalendarData'])->name('admin.duty-schedules.calendar-data');
    });

    // User management routes (admin only)
    Route::post('/users', [AdminController::class, 'storeUser'])->middleware('role:admin')->name('admin.users.store');

    // Admin-only duty schedule management routes
    Route::middleware('role:admin')->group(function () {
        Route::post('/duty-schedules/check-conflicts', [AgentDutyScheduleController::class, 'checkConflicts'])->name('admin.duty-schedules.check-conflicts');
        Route::resource('duty-schedules', AgentDutyScheduleController::class);
        Route::get('/contact-submissions', [ContactSubmissionController::class, 'index'])->name('admin.contact-submissions.index');
        Route::get('/contact-submissions/{contactSubmission}', [ContactSubmissionController::class, 'show'])->name('admin.contact-submissions.show');
        Route::get('/users', [UserController::class, 'index'])->name('admin.users.index');
        Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('admin.users.reset-password');
        Route::patch('/users/{user}/toggle-status', [UserController::class, 'toggleStatus'])->name('admin.users.toggle-status');
    });
});

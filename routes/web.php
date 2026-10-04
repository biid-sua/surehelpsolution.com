<?php

use App\Http\Controllers\Admin\AgentDutyScheduleController;
use App\Http\Controllers\Admin\ContactSubmissionController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Client\CalendarEventsController;
use App\Http\Controllers\Client\CallExportController;
use App\Http\Controllers\Client\CustomerExportController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PasswordChangeController;
use App\Livewire\Admin\AuditLogs;
use App\Livewire\Admin\Calls\Review as CallReview;
use App\Livewire\Admin\Home as AdminHome;
use App\Livewire\Admin\Organizations\Index as OrganizationIndex;
use App\Livewire\Admin\Organizations\Show as OrganizationShow;
use App\Livewire\Client\Business\Hours as BusinessHoursPage;
use App\Livewire\Client\Business\Profile as BusinessProfilePage;
use App\Livewire\Client\Business\Services as BusinessServicesPage;
use App\Livewire\Client\Calendar as ClientCalendar;
use App\Livewire\Client\Calls\Index as ClientCalls;
use App\Livewire\Client\Calls\Show as ClientCallShow;
use App\Livewire\Client\Customers\Index as CustomerIndex;
use App\Livewire\Client\Customers\Show as CustomerShow;
use App\Livewire\Client\Dashboard as ClientDashboard;
use App\Livewire\Client\Settings\Notifications as NotificationSettings;
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
    // Legacy client dashboard URL, kept so old bookmarks and links keep working.
    Route::get('/client-dashboard', [AdminController::class, 'clientDashboard'])->middleware('role:client,admin')->name('admin.client-dashboard');

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

// Business portal (clients) — docs/implementation-plan.md P1-4
Route::prefix('app')->name('app.')->middleware(['auth.home', 'force.password.change', 'role:client', 'tenant'])->group(function () {
    Route::get('/', ClientDashboard::class)->middleware('can:dashboard.view')->name('dashboard');
    Route::middleware('can:calls.view')->group(function () {
        Route::get('/calls', ClientCalls::class)->name('calls.index');
        Route::get('/calls/export', CallExportController::class)->name('calls.export');
        Route::get('/calls/{callId}', ClientCallShow::class)->name('calls.show');
        Route::get('/calendar', ClientCalendar::class)->name('calendar');
        Route::get('/calendar/events', CalendarEventsController::class)->name('calendar.events');
    });
    Route::get('/settings/notifications', NotificationSettings::class)->name('settings.notifications');
    Route::middleware('can:customers.view')->group(function () {
        Route::get('/customers', CustomerIndex::class)->name('customers.index');
        Route::get('/customers/export', CustomerExportController::class)->name('customers.export');
        Route::get('/customers/{customer}', CustomerShow::class)->name('customers.show');
    });
    Route::middleware('can:organization.view')->group(function () {
        Route::get('/business', BusinessProfilePage::class)->name('business.profile');
        Route::get('/business/hours', BusinessHoursPage::class)->name('business.hours');
        Route::get('/business/services', BusinessServicesPage::class)->name('business.services');
    });
});

// Admin console (new shell)
Route::prefix('admin')->name('admin.')->middleware(['auth.home', 'force.password.change', 'role:admin'])->group(function () {
    Route::get('/', AdminHome::class)->name('home');
    Route::get('/organizations', OrganizationIndex::class)->name('organizations.index');
    Route::get('/organizations/{organization}', OrganizationShow::class)->name('organizations.show');
    Route::get('/calls/review', CallReview::class)->name('calls.review');
    Route::get('/audit', AuditLogs::class)->middleware('can:audit_logs.view')->name('audit');
});

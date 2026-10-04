<?php

use App\Http\Controllers\Agent\CallExportController as AgentCallExportController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Client\CalendarEventsController;
use App\Http\Controllers\Client\CalendarOAuthController;
use App\Http\Controllers\Client\CallExportController;
use App\Http\Controllers\Client\CustomerExportController;
use App\Http\Controllers\Client\InvoiceController as ClientInvoiceController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PasswordChangeController;
use App\Livewire\Admin\AuditLogs;
use App\Livewire\Admin\Billing\Index as AdminBilling;
use App\Livewire\Admin\Calls\Review as CallReview;
use App\Livewire\Admin\Enquiries\Index as AdminEnquiries;
use App\Livewire\Admin\Escalations\Index as AdminEscalations;
use App\Livewire\Admin\Home as AdminHome;
use App\Livewire\Admin\Organizations\Index as OrganizationIndex;
use App\Livewire\Admin\Organizations\Show as OrganizationShow;
use App\Livewire\Admin\Schedules\Index as AdminSchedules;
use App\Livewire\Admin\Users\Index as AdminUsers;
use App\Livewire\Agent\Calls as AgentCalls;
use App\Livewire\Agent\Home as AgentHome;
use App\Livewire\Agent\Schedule as AgentSchedule;
use App\Livewire\Agent\Workspace as AgentWorkspace;
use App\Livewire\Client\Appointments\Index as AppointmentsIndex;
use App\Livewire\Client\Billing\Index as ClientBilling;
use App\Livewire\Client\Business\Calendars as BusinessCalendarsPage;
use App\Livewire\Client\Business\Hours as BusinessHoursPage;
use App\Livewire\Client\Business\Knowledge as BusinessKnowledgePage;
use App\Livewire\Client\Business\Outcomes as BusinessOutcomesPage;
use App\Livewire\Client\Business\Profile as BusinessProfilePage;
use App\Livewire\Client\Business\Rules as BusinessRulesPage;
use App\Livewire\Client\Business\Services as BusinessServicesPage;
use App\Livewire\Client\Calendar as ClientCalendar;
use App\Livewire\Client\Calls\Index as ClientCalls;
use App\Livewire\Client\Calls\Show as ClientCallShow;
use App\Livewire\Client\Customers\Index as CustomerIndex;
use App\Livewire\Client\Customers\Show as CustomerShow;
use App\Livewire\Client\Dashboard as ClientDashboard;
use App\Livewire\Client\Escalations\Index as EscalationsIndex;
use App\Livewire\Client\Settings\Notifications as NotificationSettings;
use App\Livewire\Client\Tasks\Index as TasksIndex;
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

// First sign-in: agents and business owners replace their temporary password (no force middleware here)
Route::prefix('account')->middleware('auth.home')->group(function () {
    Route::get('/password', [PasswordChangeController::class, 'show'])->name('password.change');
    Route::post('/password', [PasswordChangeController::class, 'update'])->name('password.change.update');
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
    Route::get('/tasks', TasksIndex::class)->middleware('can:tasks.view')->name('tasks.index');
    Route::middleware('can:billing.view')->group(function () {
        Route::get('/billing', ClientBilling::class)->name('billing');
        Route::get('/billing/invoices/{invoice}', [ClientInvoiceController::class, 'show'])->name('billing.invoice');
        Route::get('/billing/invoices/{invoice}/pdf', [ClientInvoiceController::class, 'pdf'])->name('billing.invoice.pdf');
    });
    Route::middleware('can:integrations.manage')->whereIn('provider', ['google', 'microsoft'])->group(function () {
        Route::get('/integrations/calendar/{provider}/connect', [CalendarOAuthController::class, 'redirect'])->name('integrations.calendar.connect');
        Route::get('/integrations/calendar/{provider}/callback', [CalendarOAuthController::class, 'callback'])->name('integrations.calendar.callback');
    });
    Route::get('/appointments', AppointmentsIndex::class)->middleware('can:appointments.view')->name('appointments.index');
    Route::get('/escalations', EscalationsIndex::class)->middleware('can:escalations.view')->name('escalations.index');
    Route::middleware('can:customers.view')->group(function () {
        Route::get('/customers', CustomerIndex::class)->name('customers.index');
        Route::get('/customers/export', CustomerExportController::class)->name('customers.export');
        Route::get('/customers/{customer}', CustomerShow::class)->name('customers.show');
    });
    Route::middleware('can:organization.view')->group(function () {
        Route::get('/business', BusinessProfilePage::class)->name('business.profile');
        Route::get('/business/hours', BusinessHoursPage::class)->name('business.hours');
        Route::get('/business/services', BusinessServicesPage::class)->name('business.services');
        Route::get('/business/outcomes', BusinessOutcomesPage::class)->name('business.outcomes');
        Route::get('/business/knowledge', BusinessKnowledgePage::class)->middleware('can:knowledge_base.view')->name('business.knowledge');
        Route::get('/business/rules', BusinessRulesPage::class)->name('business.rules');
        Route::get('/business/calendars', BusinessCalendarsPage::class)->middleware('can:integrations.view')->name('business.calendars');
    });
});

// Agent workspace (spec §20–21)
Route::prefix('agent')->name('agent.')->middleware(['auth.home', 'force.password.change', 'role:agent,admin'])->group(function () {
    Route::get('/', AgentHome::class)->name('home');
    Route::get('/businesses/{organization}', AgentWorkspace::class)->name('businesses.show');
    Route::get('/calls', AgentCalls::class)->name('calls');
    Route::get('/calls/export', AgentCallExportController::class)->name('calls.export');
    Route::get('/schedule', AgentSchedule::class)->name('schedule');
});

// Admin console
Route::prefix('admin')->name('admin.')->middleware(['auth.home', 'force.password.change', 'role:admin'])->group(function () {
    Route::get('/', AdminHome::class)->name('home');
    Route::get('/organizations', OrganizationIndex::class)->name('organizations.index');
    Route::get('/organizations/{organization}', OrganizationShow::class)->name('organizations.show');
    Route::get('/calls/review', CallReview::class)->name('calls.review');
    Route::get('/escalations', AdminEscalations::class)->middleware('can:escalations.view')->name('escalations');
    Route::get('/billing', AdminBilling::class)->middleware('can:billing.view')->name('billing');
    Route::get('/users', AdminUsers::class)->middleware('can:users.view')->name('users');
    Route::get('/schedule', AdminSchedules::class)->middleware('can:users.view')->name('schedule');
    Route::get('/enquiries', AdminEnquiries::class)->middleware('can:marketing.view')->name('enquiries');
    Route::get('/audit', AuditLogs::class)->middleware('can:audit_logs.view')->name('audit');
});

<?php

use App\Http\Controllers\Account\EmailVerificationController;
use App\Http\Controllers\Account\InvitationController;
use App\Http\Controllers\Account\PasswordResetController;
use App\Http\Controllers\Account\TermsController;
use App\Http\Controllers\Account\TwoFactorChallengeController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Agent\CallExportController as AgentCallExportController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Client\CalendarEventsController;
use App\Http\Controllers\Client\CalendarOAuthController;
use App\Http\Controllers\Client\CallExportController;
use App\Http\Controllers\Client\CustomerExportController;
use App\Http\Controllers\Client\DataExportController as ClientDataExportController;
use App\Http\Controllers\Client\InvoiceController as ClientInvoiceController;
use App\Http\Controllers\Client\ResultsPdfController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PasswordChangeController;
use App\Livewire\Account\Profile as AccountProfile;
use App\Livewire\Account\Security as AccountSecurity;
use App\Livewire\Admin\AuditLogs;
use App\Livewire\Admin\Billing\Index as AdminBilling;
use App\Livewire\Admin\Calls\Review as CallReview;
use App\Livewire\Admin\Enquiries\Index as AdminEnquiries;
use App\Livewire\Admin\Escalations\Index as AdminEscalations;
use App\Livewire\Admin\Home as AdminHome;
use App\Livewire\Admin\Organizations\Index as OrganizationIndex;
use App\Livewire\Admin\Organizations\Show as OrganizationShow;
use App\Livewire\Admin\Schedules\Index as AdminSchedules;
use App\Livewire\Admin\Search as AdminSearch;
use App\Livewire\Admin\Users\Index as AdminUsers;
use App\Livewire\Agent\Calls as AgentCalls;
use App\Livewire\Agent\Home as AgentHome;
use App\Livewire\Agent\Quality as AgentQuality;
use App\Livewire\Agent\Schedule as AgentSchedule;
use App\Livewire\Agent\Workspace as AgentWorkspace;
use App\Livewire\Client\Appointments\Index as AppointmentsIndex;
use App\Livewire\Client\Billing\Index as ClientBilling;
use App\Livewire\Client\Business\Calendars as BusinessCalendarsPage;
use App\Livewire\Client\Business\CustomerEmails as BusinessCustomerEmails;
use App\Livewire\Client\Business\Hours as BusinessHoursPage;
use App\Livewire\Client\Business\Knowledge as BusinessKnowledgePage;
use App\Livewire\Client\Business\Outcomes as BusinessOutcomesPage;
use App\Livewire\Client\Business\Profile as BusinessProfilePage;
use App\Livewire\Client\Business\Rules as BusinessRulesPage;
use App\Livewire\Client\Business\Services as BusinessServicesPage;
use App\Livewire\Client\Calendar as ClientCalendar;
use App\Livewire\Client\Calls\Index as ClientCalls;
use App\Livewire\Client\Calls\Show as ClientCallShow;
use App\Livewire\Client\Customers\Duplicates as CustomerDuplicates;
use App\Livewire\Client\Customers\Index as CustomerIndex;
use App\Livewire\Client\Customers\Show as CustomerShow;
use App\Livewire\Client\Dashboard as ClientDashboard;
use App\Livewire\Client\Escalations\Index as EscalationsIndex;
use App\Livewire\Client\Results as ClientResults;
use App\Livewire\Client\Settings\Notifications as NotificationSettings;
use App\Livewire\Client\Settings\Privacy as PrivacySettings;
use App\Livewire\Client\Settings\Team as TeamSettings;
use App\Livewire\Client\Setup\Wizard as SetupWizard;
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

// Signing in (D8, D24)
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('auth.login');
    Route::get('/two-factor-challenge', [TwoFactorChallengeController::class, 'show'])->name('two-factor.challenge');
    Route::post('/two-factor-challenge', [TwoFactorChallengeController::class, 'verify'])->middleware('throttle:10,1')->name('two-factor.verify');
    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'email'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:10,1')->name('password.store');
});
Route::match(['get', 'post'], '/logout', [AuthController::class, 'logout'])->name('auth.logout');
Route::post('/impersonation/stop', [ImpersonationController::class, 'stop'])->middleware('auth.home')->name('impersonation.stop');
Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])->middleware(['signed', 'throttle:10,1'])->whereNumber('id')->name('verification.verify');

// Team invitations from business owners: open to guests (new people) and signed-in owners of the invited email.
Route::get('/invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
Route::post('/invitations/{token}', [InvitationController::class, 'accept'])->middleware('throttle:10,1')->name('invitations.accept');

// First sign-in: agents and business owners replace their temporary password (no force middleware here)
Route::prefix('account')->middleware('auth.home')->group(function () {
    Route::get('/password', [PasswordChangeController::class, 'show'])->name('password.change');
    Route::post('/password', [PasswordChangeController::class, 'update'])->name('password.change.update');
});

// Everyone's own account: profile, security (two-step sign-in, devices), terms
Route::prefix('account')->name('account.')->middleware(['auth.home', 'force.password.change', 'tenant', 'account.gate'])->group(function () {
    Route::get('/', AccountProfile::class)->name('profile');
    Route::get('/security', AccountSecurity::class)->name('security');
    Route::get('/terms', [TermsController::class, 'show'])->name('terms');
    Route::post('/terms', [TermsController::class, 'accept'])->name('terms.accept');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])->middleware('throttle:3,10')->name('verification.send');
});

// Business portal (clients) — docs/implementation-plan.md P1-4
Route::prefix('app')->name('app.')->middleware(['auth.home', 'force.password.change', 'role:client', 'tenant', 'account.gate'])->group(function () {
    Route::get('/', ClientDashboard::class)->middleware('can:dashboard.view')->name('dashboard');
    Route::get('/setup', SetupWizard::class)->middleware('can:organization.update')->name('setup');
    Route::middleware('can:reports.view')->group(function () {
        Route::get('/results', ClientResults::class)->name('results');
        Route::get('/results/pdf', ResultsPdfController::class)->name('results.pdf');
    });
    Route::middleware('can:calls.view')->group(function () {
        Route::get('/calls', ClientCalls::class)->name('calls.index');
        Route::get('/calls/export', CallExportController::class)->name('calls.export');
        Route::get('/calls/{callId}', ClientCallShow::class)->name('calls.show');
        Route::get('/calendar', ClientCalendar::class)->name('calendar');
        Route::get('/calendar/events', CalendarEventsController::class)->name('calendar.events');
    });
    Route::get('/settings/notifications', NotificationSettings::class)->name('settings.notifications');
    Route::get('/settings/team', TeamSettings::class)->middleware('can:users.view')->name('settings.team');
    Route::middleware('can:organization.update')->group(function () {
        Route::get('/settings/privacy', PrivacySettings::class)->name('settings.privacy');
        Route::get('/settings/privacy/exports/{export}', ClientDataExportController::class)->name('settings.privacy.export');
    });
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
        Route::get('/customers/duplicates', CustomerDuplicates::class)->middleware('can:customers.delete')->name('customers.duplicates');
        Route::get('/customers/{customer}', CustomerShow::class)->name('customers.show');
    });
    Route::middleware('can:organization.view')->group(function () {
        Route::get('/business', BusinessProfilePage::class)->name('business.profile');
        Route::get('/business/hours', BusinessHoursPage::class)->name('business.hours');
        Route::get('/business/services', BusinessServicesPage::class)->name('business.services');
        Route::get('/business/outcomes', BusinessOutcomesPage::class)->name('business.outcomes');
        Route::get('/business/knowledge', BusinessKnowledgePage::class)->middleware('can:knowledge_base.view')->name('business.knowledge');
        Route::get('/business/rules', BusinessRulesPage::class)->name('business.rules');
        Route::get('/business/emails', BusinessCustomerEmails::class)->name('business.emails');
        Route::get('/business/calendars', BusinessCalendarsPage::class)->middleware('can:integrations.view')->name('business.calendars');
    });
});

// Agent workspace (spec §20–21)
Route::prefix('agent')->name('agent.')->middleware(['auth.home', 'force.password.change', 'role:agent,admin', 'account.gate'])->group(function () {
    Route::get('/', AgentHome::class)->name('home');
    Route::get('/businesses/{organization}', AgentWorkspace::class)->name('businesses.show');
    Route::get('/calls', AgentCalls::class)->name('calls');
    Route::get('/calls/export', AgentCallExportController::class)->name('calls.export');
    Route::get('/schedule', AgentSchedule::class)->name('schedule');
    Route::get('/quality', AgentQuality::class)->name('quality');
});

// Admin console
Route::prefix('admin')->name('admin.')->middleware(['auth.home', 'force.password.change', 'role:admin', 'account.gate'])->group(function () {
    Route::get('/', AdminHome::class)->name('home');
    Route::get('/search', AdminSearch::class)->name('search');
    Route::get('/organizations', OrganizationIndex::class)->name('organizations.index');
    Route::get('/organizations/{organization}', OrganizationShow::class)->name('organizations.show');
    Route::get('/calls/review', CallReview::class)->name('calls.review');
    Route::get('/escalations', AdminEscalations::class)->middleware('can:escalations.view')->name('escalations');
    Route::get('/billing', AdminBilling::class)->middleware('can:billing.view')->name('billing');
    Route::get('/users', AdminUsers::class)->middleware('can:users.view')->name('users');
    Route::post('/users/{user}/impersonate', [ImpersonationController::class, 'start'])->middleware('can:users.impersonate')->whereNumber('user')->name('impersonate');
    Route::get('/schedule', AdminSchedules::class)->middleware('can:users.view')->name('schedule');
    Route::get('/enquiries', AdminEnquiries::class)->middleware('can:marketing.view')->name('enquiries');
    Route::get('/audit', AuditLogs::class)->middleware('can:audit_logs.view')->name('audit');
});

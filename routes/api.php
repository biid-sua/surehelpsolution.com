<?php

use App\Http\Controllers\Agent\TrainingFileController;
use App\Http\Controllers\Api\AccountClosureController;
use App\Http\Controllers\Api\AdminDashboardController;
use App\Http\Controllers\Api\AgentCompanyController;
use App\Http\Controllers\Api\AgentDashboardController;
use App\Http\Controllers\Api\AgentWorkController;
use App\Http\Controllers\Api\AppNotificationController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AppVersionController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\CallDetailController;
use App\Http\Controllers\Api\CapabilitiesController;
use App\Http\Controllers\Api\ClientBusinessController;
use App\Http\Controllers\Api\ClientDashboardController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\DutyScheduleController;
use App\Http\Controllers\Api\EscalationController;
use App\Http\Controllers\Api\InboxActionsController;
use App\Http\Controllers\Api\InboxController;
use App\Http\Controllers\Api\KnowledgeController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\NotificationSettingsController;
use App\Http\Controllers\Api\OpenApiController;
use App\Http\Controllers\Api\ResultsController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SocialManageController;
use App\Http\Controllers\Api\SocialPostController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\ThemeController as ApiThemeController;
use App\Http\Controllers\Api\TrainingController;
use App\Http\Controllers\Chat\SiteToolsController;
use App\Http\Controllers\Chat\WidgetController;
use App\Http\Controllers\CspReportController;
use App\Http\Controllers\Webhooks\CalendarWebhookController;
use App\Http\Controllers\Webhooks\MetaWebhookController;
use App\Http\Controllers\Webhooks\SmsWebhookController;
use App\Http\Controllers\Webhooks\StripeWebhookController;
use App\Http\Controllers\Webhooks\VoiceWebhookController;
use App\Http\Middleware\VerifyTelephonyWebhook;
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

// Live calls: Twilio drives each call through these (signed with the auth token, D60).
Route::prefix('webhooks/voice')->name('webhooks.voice.')->middleware([VerifyTelephonyWebhook::class, 'throttle:1200,1'])->group(function () {
    Route::post('/incoming', [VoiceWebhookController::class, 'incoming'])->name('incoming');
    Route::post('/status', [VoiceWebhookController::class, 'status'])->name('status');
    Route::post('/outgoing', [VoiceWebhookController::class, 'outgoing'])->name('outgoing');
    Route::post('/calls/{voiceCall}/callee', [VoiceWebhookController::class, 'callee'])->name('callee');
    Route::post('/calls/{voiceCall}/agent', [VoiceWebhookController::class, 'agentLeg'])->name('agent-leg');
    Route::post('/calls/{voiceCall}/dial-finished', [VoiceWebhookController::class, 'dialFinished'])->name('dial-finished');
    Route::post('/calls/{voiceCall}/transfer', [VoiceWebhookController::class, 'transfer'])->name('transfer');
    Route::post('/calls/{voiceCall}/hold', [VoiceWebhookController::class, 'hold'])->name('hold');
    Route::post('/calls/{voiceCall}/join', [VoiceWebhookController::class, 'join'])->name('join');
    Route::post('/calls/{voiceCall}/owner-finished', [VoiceWebhookController::class, 'ownerFinished'])->name('owner-finished');
    Route::post('/calls/{voiceCall}/voicemail-saved', [VoiceWebhookController::class, 'voicemailSaved'])->name('voicemail-saved');
    Route::post('/calls/{voiceCall}/recording', [VoiceWebhookController::class, 'recording'])->name('recording');
    Route::post('/calls/{voiceCall}/transcription', [VoiceWebhookController::class, 'transcription'])->name('transcription');
});

// Texts to SureHelp numbers and delivery reports (signed by Twilio, D61).
Route::prefix('webhooks/sms')->name('webhooks.sms.')->middleware([VerifyTelephonyWebhook::class, 'throttle:1200,1'])->group(function () {
    Route::post('/incoming', [SmsWebhookController::class, 'incoming'])->name('incoming');
    Route::post('/status', [SmsWebhookController::class, 'status'])->name('status');
});

// Card payments: Stripe confirms payments here (signed with the endpoint secret, D64).
// What the Content-Security-Policy blocked, reported by browsers (D67).
Route::post('/csp-report', CspReportController::class)->middleware('throttle:60,1')->name('csp.report');

Route::post('/webhooks/stripe', StripeWebhookController::class)->middleware('throttle:600,1')->name('webhooks.stripe');

Route::prefix('v1')->middleware('throttle:api')->group(function () {
    // Authentication routes
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    // The API description, generated from these routes (API-19)
    Route::get('/openapi.json', OpenApiController::class)->middleware('throttle:30,1');
    // The app's update check (API-5): no sign-in, so too-old builds can still be told to update.
    Route::get('/app/version', AppVersionController::class)->middleware('throttle:60,1');
    // A call recording from a short-lived signed link (API-6): audio players can't send a token.
    // A picture texted to a business, from a short-lived signed link (API-7).
    Route::get('/inbox-media/{organization}/{file}', [InboxActionsController::class, 'media'])->middleware(['signed', 'throttle:240,1'])->name('api.inbox.media');
    Route::get('/recordings/{voiceCall}', [CallDetailController::class, 'recording'])->middleware(['signed', 'throttle:60,1'])->name('api.recordings.play');
});

// Protected API routes (authentication required, active accounts only)
Route::prefix('v1')->middleware(['auth:sanctum', 'api.active', 'throttle:api'])->group(function () {
    // User profile and authentication
    Route::get('/user', [AuthController::class, 'me']);
    // Colour themes, shared with the web (D59)
    Route::get('/themes', [ApiThemeController::class, 'index']);
    Route::put('/user/theme', [ApiThemeController::class, 'update'])->middleware('throttle:30,1');
    Route::post('/refresh-token', [AuthController::class, 'refresh']);

    // What this person may use (API-1), and closing the account from the app (API-4)
    Route::get('/me/capabilities', CapabilitiesController::class);
    Route::get('/account/closure', [AccountClosureController::class, 'show']);
    // Notification settings and the verified mobile number for notification texts (API-3)
    Route::get('/notifications/settings', [NotificationSettingsController::class, 'show']);
    Route::put('/notifications/settings', [NotificationSettingsController::class, 'update']);
    Route::post('/account/sms-phone', [NotificationSettingsController::class, 'sendPhoneCode'])->middleware('throttle:6,10');
    Route::post('/account/sms-phone/verify', [NotificationSettingsController::class, 'verifyPhone'])->middleware('throttle:10,10');
    Route::delete('/account/sms-phone', [NotificationSettingsController::class, 'removePhone']);
    Route::post('/account/closure', [AccountClosureController::class, 'store'])->middleware('throttle:10,1');
    Route::delete('/account/closure', [AccountClosureController::class, 'destroy']);

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
            Route::get('/calls/{callId}', [CallDetailController::class, 'show']);   // API-6
        });
        // Search across the business, as on the web (API-17)
        Route::get('/search', SearchController::class)->middleware(['can:dashboard.view', 'throttle:60,1']);
        // Results, as on the web and in the monthly PDF (API-10)
        Route::middleware('can:reports.view')->group(function () {
            Route::get('/results', [ResultsController::class, 'show']);
            Route::get('/results/pdf', [ResultsController::class, 'pdf'])->name('api.client.results.pdf');
        });
        Route::get('/business', [ClientBusinessController::class, 'show'])->middleware('can:organization.view');
        Route::get('/services', [ClientBusinessController::class, 'services'])->middleware('can:organization.view');
        Route::get('/knowledge', [KnowledgeController::class, 'index'])->middleware('can:knowledge_base.view');
        Route::get('/rules', [KnowledgeController::class, 'rules'])->middleware('can:organization.view');
        Route::middleware('can:customers.view')->group(function () {
            Route::get('/customers', [CustomerController::class, 'index']);
            Route::get('/customers/lookup', [CustomerController::class, 'lookup']);   // API-8
            Route::get('/customers/{ulid}', [CustomerController::class, 'show']);
        });
        // Adding and editing customers (API-8)
        Route::post('/customers', [CustomerController::class, 'store'])->middleware(['can:customers.create', 'idempotent']);
        Route::middleware('can:customers.update')->group(function () {
            Route::patch('/customers/{ulid}', [CustomerController::class, 'update']);
            Route::post('/customers/{ulid}/notes', [CustomerController::class, 'addNote'])->middleware('idempotent');
            Route::post('/customers/{ulid}/tags', [CustomerController::class, 'addTag']);
            Route::delete('/customers/{ulid}/tags/{tag}', [CustomerController::class, 'removeTag'])->whereNumber('tag');
        });
        Route::get('/inbox/conversations', [InboxController::class, 'index'])->middleware('can:messages.view');
        Route::get('/inbox/conversations/{ulid}', [InboxController::class, 'show'])->middleware('can:messages.view');
        Route::post('/inbox/conversations/{ulid}/reply', [InboxController::class, 'reply'])->middleware(['can:messages.send', 'idempotent']);
        // Inbox actions (API-7)
        Route::post('/inbox/conversations/{ulid}/read', [InboxActionsController::class, 'read'])->middleware('can:messages.view');
        Route::post('/inbox/conversations/{ulid}/messages/{message}/rate', [InboxActionsController::class, 'rate'])->middleware('can:messages.view');
        Route::middleware('can:messages.send')->group(function () {
            Route::post('/inbox/conversations', [InboxActionsController::class, 'start'])->middleware('idempotent');
            Route::get('/inbox/assignees', [InboxActionsController::class, 'assignees']);
            Route::post('/inbox/conversations/{ulid}/assign', [InboxActionsController::class, 'assign']);
            Route::post('/inbox/conversations/{ulid}/close', [InboxActionsController::class, 'close']);
            Route::post('/inbox/conversations/{ulid}/reopen', [InboxActionsController::class, 'reopen']);
            Route::post('/inbox/conversations/{ulid}/ai', [InboxActionsController::class, 'ai']);
            Route::post('/inbox/conversations/{ulid}/drafts/{message}/send', [InboxActionsController::class, 'sendDraft'])->middleware('idempotent');
            Route::post('/inbox/conversations/{ulid}/drafts/{message}/discard', [InboxActionsController::class, 'discardDraft']);
        });
        Route::get('/social/posts', [SocialPostController::class, 'index'])->middleware('can:social.view');
        // Writing and scheduling posts (API-9)
        Route::middleware('feature:social_publishing')->group(function () {
            Route::middleware('can:social.view')->group(function () {
                Route::get('/social/accounts', [SocialManageController::class, 'accounts']);
                Route::get('/social/posts/{ulid}', [SocialManageController::class, 'show']);
                Route::get('/social/openings', [SocialManageController::class, 'openings']);
                Route::get('/social/link-page', [SocialManageController::class, 'linkPage']);
            });
            Route::middleware('can:social.manage')->group(function () {
                Route::post('/social/posts/check', [SocialManageController::class, 'check']);
                Route::post('/social/posts', [SocialManageController::class, 'store'])->middleware('idempotent');
                Route::put('/social/posts/{ulid}', [SocialManageController::class, 'update']);
                Route::post('/social/posts/{ulid}/cancel', [SocialManageController::class, 'cancel']);
                Route::post('/social/posts/{ulid}/retry', [SocialManageController::class, 'retry']);
                Route::delete('/social/posts/{ulid}', [SocialManageController::class, 'destroy']);
                Route::post('/social/media', [SocialManageController::class, 'upload'])->middleware('throttle:30,1');
                Route::post('/social/ai/write', [SocialManageController::class, 'write'])->middleware('throttle:20,1');
                Route::post('/social/ai/rewrite', [SocialManageController::class, 'rewrite'])->middleware('throttle:30,1');
                Route::put('/social/link-page', [SocialManageController::class, 'updateLinkPage']);
            });
        });
        // Billing (API-11): everyone with billing.view sees it, owners pay
        Route::middleware('can:billing.view')->group(function () {
            Route::get('/billing', [BillingController::class, 'summary']);
            Route::get('/billing/invoices', [BillingController::class, 'index']);
            Route::get('/billing/invoices/{ulid}', [BillingController::class, 'show']);
            Route::get('/billing/invoices/{ulid}/pdf', [BillingController::class, 'pdf'])->name('api.client.billing.invoice.pdf');
        });
        Route::middleware('can:billing.manage')->group(function () {
            Route::post('/billing/invoices/{ulid}/pay', [BillingController::class, 'pay'])->middleware('throttle:10,1');
            Route::post('/billing/invoices/{ulid}/report-payment', [BillingController::class, 'reportPayment']);
            Route::delete('/billing/autopay', [BillingController::class, 'stopAutopay']);
        });
        // Team (API-12)
        Route::get('/team', [TeamController::class, 'index'])->middleware('can:users.view');
        Route::middleware('can:users.create')->group(function () {
            Route::post('/team/invitations', [TeamController::class, 'invite'])->middleware('throttle:20,10');
            Route::post('/team/invitations/{id}/resend', [TeamController::class, 'resend'])->whereNumber('id')->middleware('throttle:20,10');
            Route::delete('/team/invitations/{id}', [TeamController::class, 'revoke'])->whereNumber('id');
        });
        Route::middleware('can:users.delete')->group(function () {
            Route::patch('/team/members/{id}', [TeamController::class, 'changeRole'])->whereNumber('id');
            Route::delete('/team/members/{id}', [TeamController::class, 'remove'])->whereNumber('id');
        });
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
        // Shift requests (API-13), my quality reviews (API-14) and phone availability (API-15)
        Route::get('/shift-requests', [AgentWorkController::class, 'shiftRequests']);
        Route::post('/shift-requests', [AgentWorkController::class, 'requestShiftChange'])->middleware(['idempotent', 'throttle:20,10']);
        Route::post('/shift-requests/{ulid}/withdraw', [AgentWorkController::class, 'withdrawShiftRequest']);
        Route::get('/quality/reviews', [AgentWorkController::class, 'reviews']);
        Route::get('/quality/reviews/{ulid}', [AgentWorkController::class, 'review']);
        Route::post('/quality/reviews/{ulid}/acknowledge', [AgentWorkController::class, 'acknowledgeReview']);
        Route::get('/presence', [AgentWorkController::class, 'presence']);
        Route::put('/presence', [AgentWorkController::class, 'setPresence'])->middleware('throttle:30,1');
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
        // The in-app notification centre (API-2)
        Route::get('/', [AppNotificationController::class, 'index']);
        Route::get('/unread-count', [AppNotificationController::class, 'unreadCount']);
        Route::post('/read-all', [AppNotificationController::class, 'readAll']);
        Route::post('/{id}/read', [AppNotificationController::class, 'read'])->whereUuid('id');
        Route::delete('/{id}', [AppNotificationController::class, 'destroy'])->whereUuid('id');
        Route::post('/device-token', [NotificationController::class, 'storeDeviceToken']);
        Route::delete('/device-token', [NotificationController::class, 'destroyDeviceToken']);
        Route::post('/test', [NotificationController::class, 'sendTestNotification'])->middleware('role:admin');
    });
});

<?php

use App\Models\AuditLog;
use App\Models\TrustedDevice;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled work (spec §52, §86) — one cron entry runs all of it:
|   * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
| See docs/deployment.md.
|--------------------------------------------------------------------------
*/

// Background jobs (notifications, emails) on hosts without a long-running worker (cPanel).
// Replaced by Horizon/supervisor workers once hosting moves (docs/decisions.md D6).
Schedule::command('queue:work', ['--stop-when-empty', '--max-time=55', '--tries=3', '--backoff=30'])
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground();

// Retention: audit entries older than config('audit.retention_days'), and expired remembered devices (D103).
Schedule::command('model:prune', ['--model' => [AuditLog::class, TrustedDevice::class]])->dailyAt('03:10');

// Failed jobs are kept a week for inspection, then removed.
Schedule::command('queue:prune-failed', ['--hours' => 168])->dailyAt('03:20');

// Overdue call-backs and tasks: one reminder each (spec §24).
Schedule::command('tasks:notify-overdue')->everyFiveMinutes()->withoutOverlapping(10);

// Urgent escalations nobody acknowledged: one more push (spec §25).
Schedule::command('escalations:remind')->everyMinute()->withoutOverlapping(5);

// Connected calendars: busy times every 10 minutes even if push notifications fail; push renewed twice a day.
Schedule::command('calendar:sync')->everyFiveMinutes()->withoutOverlapping(10);   // backup for missed push notifications
Schedule::command('calendar:sync', ['--renew-push'])->twiceDaily(4, 16)->withoutOverlapping(30);

// Billing: renewals, invoices and overdue reminders once a day (docs/billing.md).
Schedule::command('billing:run')->dailyAt('06:05')->withoutOverlapping(60);
// Daily, not monthly: each business gets last month's report once, on its own morning of the 1st (or the next run).
Schedule::command('reports:monthly')->dailyAt('14:10')->withoutOverlapping(60);
Schedule::command('notifications:daily-summary')->everyFifteenMinutes()->withoutOverlapping(30);
Schedule::command('appointments:send-reminders')->everyFifteenMinutes()->withoutOverlapping(30);
// Quality: yesterday's random call sample for supervisors (SUP-04).
Schedule::command('quality:sample')->dailyAt('05:40')->withoutOverlapping(30);
// Data retention, expired exports and account closures (spec §56, CMP-05/07).
Schedule::command('privacy:run')->dailyAt('04:20')->withoutOverlapping(60);

// Social posts: SureHelp's own publishing queue (D33). Due posts and retries every minute.
Schedule::command('social:publish-due')->everyMinute()->withoutOverlapping(5);
// Automation steps that are due (D50).
Schedule::command('automations:run')->everyMinute()->withoutOverlapping(5);
Schedule::command('reviews:sync')->hourly()->withoutOverlapping(30);
Schedule::command('backup:database')->dailyAt('03:15')->withoutOverlapping(120);   // D91

// Agent company assignments: start scheduled ones and end expired ones (D40). Access itself never waits for this.
Schedule::command('assignments:sweep')->everyFiveMinutes()->withoutOverlapping(10);
// Demo calls with the SureHelp team: reminders, sign-up spotting and nudges (D96).
Schedule::command('sales:demo-follow-ups')->everyFiveMinutes()->withoutOverlapping(10);
// Agent University reminders: due soon, overdue, certification expiring or expired (sent once each).
Schedule::command('training:sweep')->hourly()->withoutOverlapping(30);
// Agents' own calendars: nightly catch-up of copied shifts (D54). Changes are copied as they happen.
Schedule::command('agent-calendars:sync')->dailyAt('03:40')->withoutOverlapping(60);
// Monthly website health and SEO checks (spec §41B, D44).
// SEO service (D57): Search Console statistics daily; monthly autopilot on the 1st.
Schedule::command('seo:daily')->dailyAt('08:10')->withoutOverlapping(60);
Schedule::command('websites:check')->dailyAt('07:20')->withoutOverlapping(60);

// Proof the cron entry runs, for launch:check and Admin › System status (D79).
Schedule::call(fn () => Cache::put('scheduler:heartbeat', now()->timestamp, now()->addHour()))->everyMinute()->name('scheduler-heartbeat');

// Businesses' own website domains (D101): watch for the DNS record and the certificate.
Schedule::command('sites:check-domains')->everyTenMinutes()->withoutOverlapping(15);

// SureHelp's own social media (D104): the AI prepares the next 24 hours of scheduled posts.
Schedule::command('social:house-schedule')->everyFifteenMinutes()->withoutOverlapping(30);

// QuickBooks Online (D110): customers added or changed there come into SureHelp.
Schedule::command('apps:quickbooks-pull')->hourlyAt(25)->withoutOverlapping(30);

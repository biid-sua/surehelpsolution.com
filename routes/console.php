<?php

use App\Models\AuditLog;
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

// Retention: audit entries older than config('audit.retention_days').
Schedule::command('model:prune', ['--model' => [AuditLog::class]])->dailyAt('03:10');

// Failed jobs are kept a week for inspection, then removed.
Schedule::command('queue:prune-failed', ['--hours' => 168])->dailyAt('03:20');

// Overdue call-backs and tasks: one reminder each (spec §24).
Schedule::command('tasks:notify-overdue')->everyFiveMinutes()->withoutOverlapping(10);

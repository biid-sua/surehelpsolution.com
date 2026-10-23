<?php

namespace App\Services\System;

use App\Mail\ErrorAlertMail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails the team about server errors (D91), so problems are seen before customers report them. The
 * same error at most once per 15 minutes, at most 20 emails an hour. Never personal data: the error,
 * where it happened, the address and the user id only.
 */
class ErrorAlerts
{
    public function handle(Throwable $e): void
    {
        $to = (string) config('ops.alert_email');
        if ($to === '' || app()->runningUnitTests() && ! config('ops.alerts_in_tests')) {
            return;
        }
        try {
            $fingerprint = md5($e::class.'|'.$e->getFile().'|'.$e->getLine());
            $minutes = max(1, (int) config('ops.alert_throttle_minutes', 15));
            if (! Cache::add('ops-alert:'.$fingerprint, true, now()->addMinutes($minutes))) {
                return;
            }
            $hour = 'ops-alerts:'.now()->format('YmdH');
            Cache::add($hour, 0, now()->addHours(2));
            if (Cache::increment($hour) > 20) {
                return;
            }
            $request = app()->runningInConsole() ? null : request();
            Mail::to($to)->send(new ErrorAlertMail(
                class: $e::class,
                message: mb_substr($e->getMessage(), 0, 500),
                where: str_replace(base_path().'/', '', $e->getFile()).':'.$e->getLine(),
                url: $request ? $request->method().' '.$request->path() : 'console: '.implode(' ', array_slice($_SERVER['argv'] ?? [], 1, 3)),
                userId: $request?->user()?->getAuthIdentifier(),
                trace: collect($e->getTrace())->take(8)->map(fn (array $f) => str_replace(base_path().'/', '', ($f['file'] ?? '?').':'.($f['line'] ?? '?')).' '.($f['class'] ?? '').($f['type'] ?? '').$f['function'])->all(),
            ));
        } catch (Throwable) {
            // Alerts must never cause another error.
        }
    }
}

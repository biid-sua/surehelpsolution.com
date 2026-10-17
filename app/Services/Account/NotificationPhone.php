<?php

namespace App\Services\Account;

use App\Exceptions\TelephonyUnavailable;
use App\Models\PhoneNumber;
use App\Models\User;
use App\Services\Telephony\TelephonyProvider;
use App\Support\Audit\Audit;
use App\Support\NotificationChannels;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The mobile number someone gets notification texts on (D63). It's only used after they type
 * back a 6-digit code we texted to it: 10 minutes, 5 tries, 3 codes per 10 minutes.
 */
class NotificationPhone
{
    public function __construct(
        private readonly TelephonyProvider $provider,
        private readonly Audit $audit,
    ) {}

    /** @throws TelephonyUnavailable with a message safe to show */
    public function sendCode(User $user, string $phone): void
    {
        if (! NotificationChannels::enabled('sms')) {
            throw new TelephonyUnavailable('Notification texts aren\'t available yet.');
        }
        $e164 = PhoneNumber::normalize($phone);
        if ($e164 === null || ! preg_match('/^\+1\d{10}$/', $e164)) {
            throw new TelephonyUnavailable('Enter a US or Canadian mobile number.');
        }
        $key = 'notification-phone:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            throw new TelephonyUnavailable('Too many codes. Try again in '.ceil(RateLimiter::availableIn($key) / 60).' minutes.');
        }
        RateLimiter::hit($key, 600);

        $code = (string) random_int(100000, 999999);
        $user->forceFill([
            'sms_phone' => $e164, 'sms_phone_verified_at' => null,
            'sms_code_hash' => Hash::make($code), 'sms_code_expires_at' => now()->addMinutes(10), 'sms_code_attempts' => 0,
        ])->save();
        $this->provider->sendSms((string) config('telephony.notify_from'), $e164, "Your SureHelp code is {$code}. It expires in 10 minutes. If you didn't ask for it, ignore this text.", route('webhooks.sms.status'));
    }

    /** True when the code matches; the number is then verified. */
    public function verify(User $user, string $code): bool
    {
        if (! $user->sms_code_hash || ! $user->sms_code_expires_at || $user->sms_code_expires_at->isPast() || $user->sms_code_attempts >= 5) {
            return false;
        }
        $user->forceFill(['sms_code_attempts' => $user->sms_code_attempts + 1])->save();
        if (! Hash::check(preg_replace('/\D/', '', $code) ?? '', $user->sms_code_hash)) {
            return false;
        }

        $user->forceFill(['sms_phone_verified_at' => now(), 'sms_code_hash' => null, 'sms_code_expires_at' => null, 'sms_code_attempts' => 0])->save();
        $this->audit->record('account.notification_phone_verified', $user, new: ['phone' => $user->sms_phone], actor: $user, label: PhoneNumber::pretty($user->sms_phone));

        return true;
    }

    public function remove(User $user): void
    {
        $user->forceFill(['sms_phone' => null, 'sms_phone_verified_at' => null, 'sms_code_hash' => null, 'sms_code_expires_at' => null, 'sms_code_attempts' => 0])->save();
        $this->audit->record('account.notification_phone_removed', $user, actor: $user);
    }
}

<?php

namespace App\Services\Account;

use App\Models\TrustedDevice;
use App\Models\User;
use App\Support\Account\DeviceName;
use App\Support\Audit\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * "Remember this device" at the two-step sign-in (D103). The browser keeps a random secret in an
 * encrypted, HTTP-only cookie; we keep only its hash, tied to one person, so a device can be listed,
 * forgotten, and is forgotten everywhere when the password changes or two-step sign-in is reset.
 * The password is still asked for every time: only the code is skipped.
 */
class TrustedDevices
{
    public const COOKIE = 'sh_trusted_device';

    public function __construct(private readonly Audit $audit) {}

    public function days(): int
    {
        return max(0, (int) config('account.two_factor.remember_days', 30));
    }

    public function enabled(): bool
    {
        return $this->days() > 0;
    }

    /** Remember this browser for the person who just passed the code step. */
    public function remember(User $user, Request $request): void
    {
        if (! $this->enabled()) {
            return;
        }
        $secret = Str::random(64);
        $device = TrustedDevice::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $secret),
            'name' => DeviceName::from($request->userAgent()),
            'ip_address' => $request->ip(),
            'last_used_at' => now(),
            'expires_at' => now()->addDays($this->days()),
        ]);
        Cookie::queue(Cookie::make(self::COOKIE, $secret, $this->days() * 24 * 60, httpOnly: true, sameSite: 'lax'));
        $this->audit->record('auth.device_trusted', $user, new: ['device' => $device->name, 'days' => $this->days()], actor: $user);
    }

    /** Does this browser carry a live remembered-device secret for this person? */
    public function recognises(User $user, Request $request): bool
    {
        $secret = $request->cookie(self::COOKIE);
        if (! $this->enabled() || ! is_string($secret) || strlen($secret) !== 64) {
            return false;
        }
        $device = TrustedDevice::query()->where('user_id', $user->id)->where('token_hash', hash('sha256', $secret))
            ->where('expires_at', '>', now())->first();
        if (! $device) {
            return false;
        }
        $device->forceFill(['last_used_at' => now(), 'ip_address' => $request->ip()])->save();

        return true;
    }

    public function forget(TrustedDevice $device): void
    {
        $device->delete();
        $this->audit->record('auth.device_forgotten', $device->user, new: ['device' => $device->name]);
    }

    /** Every remembered browser asks for a code again. */
    public function forgetAll(User $user): int
    {
        return TrustedDevice::query()->where('user_id', $user->id)->delete();
    }
}

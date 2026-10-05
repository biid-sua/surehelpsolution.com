<?php

namespace App\Services\Account;

use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Finishing and ending web sessions (D8, D24). Every web session carries the user's
 * `session_epoch`; bumping it signs that person out on every device at their next request.
 */
class SignIn
{
    public const PENDING = 'login.two_factor';

    public const NOTICE = 'auth.notice';

    public function __construct(private readonly Audit $audit) {}

    /** Password is right: either sign in now, or park the user until their two-step code is checked. */
    public function afterPassword(User $user, Request $request): string
    {
        if ($user->hasTwoFactor()) {
            $request->session()->put(self::PENDING, ['id' => $user->id, 'at' => now()->timestamp]);

            return route('two-factor.challenge');
        }

        return $this->complete($user, $request, 'password');
    }

    /** Sign in and say where to go next. */
    public function complete(User $user, Request $request, string $method): string
    {
        $request->session()->forget(self::PENDING);
        Auth::login($user);
        $request->session()->regenerate();   // new session id: prevents session fixation
        $request->session()->put([
            'auth.epoch' => (int) $user->session_epoch,
            'auth.last_activity' => now()->timestamp,
        ]);
        if ($user->requiresTwoFactor() && ! $user->hasTwoFactor()) {
            $request->session()->put('two_factor.setup_required', true);
        }

        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        $this->audit->record('auth.login', $user, new: ['channel' => 'web', 'method' => $method], actor: $user);

        if ($user->requiresPasswordChange()) {
            return route('password.change');
        }

        return $request->session()->pull('url.intended', $user->homeUrl());
    }

    public function logout(Request $request, ?string $notice = null): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        if ($notice) {
            $request->session()->put(self::NOTICE, $notice);
        }
    }

    /**
     * Sign a person out everywhere except, optionally, this browser. Their mobile app tokens go too
     * unless kept on purpose.
     */
    public function signOutEverywhere(User $user, ?Request $keep = null, bool $includeApp = true): void
    {
        $user->forceFill(['session_epoch' => (int) $user->session_epoch + 1])->saveQuietly();
        if ($keep) {
            session()->put('auth.epoch', (int) $user->session_epoch);
        }

        // With database sessions, also remove the rows so the device list updates straight away.
        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->when($keep !== null, fn ($q) => $q->where('id', '!=', session()->getId()))
                ->delete();
        }

        if ($includeApp) {
            $user->tokens()->delete();
        }
    }
}

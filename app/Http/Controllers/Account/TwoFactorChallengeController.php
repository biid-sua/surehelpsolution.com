<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Account\SignIn;
use App\Services\Account\TwoFactor;
use App\Support\Audit\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Second step of signing in: a code from the authenticator app, or a one-time recovery code.
 * The password step parks the user id in the session for 10 minutes; nothing is signed in until here.
 */
class TwoFactorChallengeController extends Controller
{
    private const MINUTES = 10;

    public function __construct(private readonly SignIn $signIn) {}

    public function show(Request $request): View|RedirectResponse
    {
        return $this->pending($request) ? view('auth.two-factor-challenge') : redirect()->route('login');
    }

    public function verify(Request $request, TwoFactor $twoFactor, Audit $audit): RedirectResponse
    {
        $user = $this->pending($request);
        if (! $user) {
            $request->session()->put(SignIn::NOTICE, 'That took too long. Please sign in again.');

            return redirect()->route('login');
        }

        // The app code and the recovery code have their own fields; whichever was filled in counts.
        $recovery = $request->filled('recovery_code');
        $code = trim((string) ($recovery ? $request->input('recovery_code') : $request->input('code')));
        if ($code === '' || strlen($code) > 20) {
            throw ValidationException::withMessages(['code' => 'Enter the 6-digit code from your app, or a recovery code.'])->redirectTo(route('two-factor.challenge'));
        }

        $key = 'two-factor-challenge:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['code' => 'Too many tries. Wait '.RateLimiter::availableIn($key).' seconds and try again.']);
        }

        $recovery = $recovery || str_contains($code, '-');
        if (! $twoFactor->check($user, $code)) {
            RateLimiter::hit($key, 60);
            $audit->record('auth.login_failed', $user, new: ['channel' => 'web', 'reason' => 'two_factor'], actor: $user);

            throw ValidationException::withMessages(['code' => $recovery ? 'That recovery code isn\'t valid or was already used.' : 'That code isn\'t right. Codes change every 30 seconds; try the current one.'])
                ->redirectTo(route('two-factor.challenge'));
        }

        RateLimiter::clear($key);
        $next = $this->signIn->complete($user, $request, $recovery ? 'recovery_code' : 'two_factor');
        if ($recovery) {
            $left = count($user->fresh()->two_factor_recovery_codes ?? []);
            $request->session()->flash('status', "You used a recovery code. {$left} left: make new ones under Account › Security.");
        }

        return redirect()->to($next);
    }

    private function pending(Request $request): ?User
    {
        $pending = $request->session()->get(SignIn::PENDING);
        if (! is_array($pending) || now()->timestamp - (int) $pending['at'] > self::MINUTES * 60) {
            return null;
        }

        $user = User::find($pending['id']);

        return $user && $user->is_active && $user->hasTwoFactor() ? $user : null;
    }
}

<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Account\SignIn;
use App\Support\Audit\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * "Forgot password" by email (spec AUTH-01). The answer never reveals whether an email has an
 * account. A successful reset signs the person out everywhere else, mobile app included.
 */
class PasswordResetController extends Controller
{
    private const SENT = 'If that email has a SureHelp account, a reset link is on its way. Check your inbox (and spam folder).';

    public function request(): View
    {
        return view('auth.forgot-password');
    }

    public function email(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email', 'max:255']]);

        $user = User::where('email', trim((string) $request->email))->first();
        if ($user?->is_active) {
            Password::broker()->sendResetLink(['email' => $user->email]);
            app(Audit::class)->record('auth.password_reset_requested', $user, actor: $user);
        }

        return back()->with('status', self::SENT);
    }

    public function edit(Request $request, string $token): View
    {
        return view('auth.reset-password', ['token' => $token, 'email' => (string) $request->query('email', '')]);
    }

    public function update(Request $request, SignIn $signIn): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::broker()->reset($request->only('email', 'password', 'password_confirmation', 'token'), function (User $user, string $password) use ($signIn) {
            // A reset proves the person reads this inbox, so it also confirms the email address.
            $user->forceFill([
                'password' => $password,
                'must_change_password' => false,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();
            $signIn->signOutEverywhere($user);
            app(Audit::class)->record('auth.password_reset', $user, actor: $user);
        });

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withInput($request->only('email'))
                ->withErrors(['email' => 'This reset link is no longer valid. It may have expired or been used already, so ask for a new one.']);
        }

        $request->session()->put(SignIn::NOTICE, 'Your password is changed. Sign in with the new one.');

        return redirect()->route('login');
    }
}

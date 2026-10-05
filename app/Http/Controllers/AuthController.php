<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Account\Impersonation;
use App\Services\Account\SignIn;
use App\Support\Audit\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Web sign-in and sign-out. Answers a normal form post with redirects, and a JSON request with
 * `{ success, redirect }`, so scripted clients keep working.
 */
class AuthController extends Controller
{
    public function __construct(private readonly SignIn $signIn) {}

    public function show(Request $request): View
    {
        return view('auth.login', ['notice' => $request->session()->pull(SignIn::NOTICE)]);
    }

    public function login(Request $request): JsonResponse|RedirectResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', trim((string) $request->email))->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            // Only the attempted email is kept; the password never reaches the audit log.
            app(Audit::class)->record('auth.login_failed', $user, new: ['channel' => 'web', 'email' => $request->email], actor: $user);

            throw ValidationException::withMessages([
                'email' => ['That email and password don\'t match an account.'],
            ]);
        }

        if (! $user->is_active) {
            app(Audit::class)->record('auth.login_blocked', $user, new: ['channel' => 'web', 'reason' => 'deactivated'], actor: $user);
            $message = 'Your account is switched off. Please contact the person who manages your SureHelp account.';

            return $request->expectsJson()
                ? response()->json(['success' => false, 'account_deactivated' => true, 'message' => $message], 403)
                : back()->withInput($request->only('email'))->withErrors(['email' => $message]);
        }

        $next = $this->signIn->afterPassword($user, $request);

        return $request->expectsJson()
            ? response()->json([
                'success' => true,
                'two_factor' => $next === route('two-factor.challenge'),
                'must_change_password' => $user->requiresPasswordChange(),
                'redirect' => $next,
            ])
            : redirect()->to($next);
    }

    public function logout(Request $request, Impersonation $impersonation): RedirectResponse
    {
        // Signing out while viewing as a client just stops viewing as them.
        if ($impersonation->active($request)) {
            return redirect()->to($impersonation->stop($request) ?? route('login'));
        }
        $this->signIn->logout($request, 'You\'re signed out.');

        return redirect()->route('login');
    }
}

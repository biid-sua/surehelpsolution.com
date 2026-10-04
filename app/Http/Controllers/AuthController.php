<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Handle user login
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            // Only the attempted email is kept; the password never reaches the audit log.
            app(Audit::class)->record('auth.login_failed', $user, new: ['channel' => 'web', 'email' => $request->email], actor: $user);

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->is_active) {
            app(Audit::class)->record('auth.login_blocked', $user, new: ['channel' => 'web', 'reason' => 'deactivated'], actor: $user);

            return response()->json([
                'success' => false,
                'account_deactivated' => true,
                'message' => 'Your account has been deactivated. Please contact the concerned person for assistance.',
            ], 403);
        }

        Auth::login($user);

        // New session ID after login: prevents session fixation.
        $request->session()->regenerate();

        if ($user->requiresPasswordChange()) {
            return response()->json([
                'success' => true,
                'must_change_password' => true,
                'redirect' => route('password.change'),
            ]);
        }

        // Each portal type has one home (User::homeUrl).
        return response()->json([
            'success' => true,
            'redirect' => $user->homeUrl(),
        ]);
    }

    /**
     * Handle user logout
     */
    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}

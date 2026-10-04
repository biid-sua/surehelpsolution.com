<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class PasswordChangeController extends Controller
{
    public function show()
    {
        $user = Auth::user();

        if (! $user || ! $user->requiresPasswordChange()) {
            return redirect($this->dashboardRouteFor($user));
        }

        return view('auth.change-password');
    }

    public function update(Request $request)
    {
        $user = Auth::user();

        if (! $user || ! in_array($user->role, ['agent', 'client'], true)) {
            abort(403, 'Unauthorized access.');
        }

        $request->validate([
            'current_password' => 'required|string',
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        if (! Hash::check($request->current_password, $user->password)) {
            return back()->withErrors(['current_password' => 'That isn\'t the temporary password you were given.']);
        }

        if (Hash::check($request->password, $user->password)) {
            return back()->withErrors(['password' => 'Your new password must be different from your current password.']);
        }

        $user->update([
            'password' => $request->password,
            'must_change_password' => false,
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Password updated successfully.',
                'redirect' => $this->dashboardRouteFor($user),
            ]);
        }

        return redirect($this->dashboardRouteFor($user))
            ->with('success', 'Your password has been updated successfully.');
    }

    private function dashboardRouteFor($user): string
    {
        return $user?->homeUrl() ?? route('home');
    }
}

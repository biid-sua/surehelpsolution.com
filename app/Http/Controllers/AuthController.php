<?php

namespace App\Http\Controllers;

use App\Models\User;
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
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->is_active) {
            return response()->json([
                'success' => false,
                'account_deactivated' => true,
                'message' => 'Your account has been deactivated. Please contact the concerned person for assistance.',
            ], 403);
        }

        Auth::login($user);

        if ($user->requiresPasswordChange()) {
            return response()->json([
                'success' => true,
                'must_change_password' => true,
                'redirect' => route('password.change'),
            ]);
        }

        // Auto-redirect based on user role
        switch ($user->role) {
            case 'admin':
                return response()->json([
                    'success' => true,
                    'redirect' => route('admin.dashboard'),
                ]);
            case 'agent':
                return response()->json([
                    'success' => true,
                    'redirect' => route('admin.agent-dashboard'),
                ]);
            case 'client':
                return response()->json([
                    'success' => true,
                    'redirect' => route('admin.client-dashboard'),
                ]);
            default:
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid user role',
                ], 400);
        }
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

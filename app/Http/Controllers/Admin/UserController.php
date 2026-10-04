<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    public function index()
    {
        $users = User::whereIn('role', ['agent', 'client'])
            ->orderBy('role')
            ->orderBy('name')
            ->get();

        return view('admin.users.index', compact('users'));
    }

    public function resetPassword(Request $request, User $user)
    {
        if (! in_array($user->role, ['agent', 'client'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Password reset is only available for agents and clients.',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user->update([
            'password' => Hash::make($request->password),
            'must_change_password' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Password reset successfully. Share the new default password with the user — they will be required to change it on next login.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'must_change_password' => $user->must_change_password,
            ],
        ]);
    }

    public function toggleStatus(User $user)
    {
        if (! in_array($user->role, ['agent', 'client'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Status can only be changed for agents and clients.',
            ], 422);
        }

        $user->update(['is_active' => ! $user->is_active]);

        if (! $user->is_active) {
            // Sign the user out of the mobile app as well.
            $user->tokens()->delete();
        }

        return response()->json([
            'success' => true,
            'message' => $user->is_active
                ? "{$user->name}'s account has been activated."
                : "{$user->name}'s account has been deactivated.",
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'is_active' => $user->is_active,
            ],
        ]);
    }
}

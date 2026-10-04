<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Handle API login
     *
     * @return JsonResponse
     */
    public function login(Request $request)
    {
        try {
            // Validate the request
            $request->validate([
                'email' => 'required|email',
                'password' => 'required|string|min:6',
            ]);

            $user = User::where('email', $request->email)->first();

            if (! $user || ! Hash::check($request->password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid credentials',
                ], 401);
            }

            if (! $user->is_active) {
                return response()->json([
                    'success' => false,
                    'account_deactivated' => true,
                    'message' => 'Your account has been deactivated. Please contact the concerned person for assistance.',
                ], 403);
            }

            // Create token
            $token = $user->createToken('auth-token')->plainTextToken;

            // Return success response with user data and token
            return response()->json([
                'success' => true,
                'message' => 'Login successful',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'role' => $user->role,
                        'phone' => $user->phone,
                        'unique_id' => $user->unique_id,
                        'is_active' => $user->is_active,
                        'must_change_password' => $user->requiresPasswordChange(),
                        'created_at' => $user->created_at,
                        'updated_at' => $user->updated_at,
                    ],
                    'token' => $token,
                    'token_type' => 'Bearer',
                    'must_change_password' => $user->requiresPasswordChange(),
                ],
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Login failed',
            ], 500);
        }
    }

    /**
     * Handle API logout
     *
     * @return JsonResponse
     */
    public function logout(Request $request)
    {
        try {
            // Revoke the current token
            $request->user()->currentAccessToken()->delete();

            return response()->json([
                'success' => true,
                'message' => 'Logout successful',
            ], 200);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Logout failed',
            ], 500);
        }
    }

    /**
     * Get authenticated user data
     *
     * @return JsonResponse
     */
    public function me(Request $request)
    {
        try {
            $user = $request->user();
            $organization = $user->isClient() ? $user->primaryOrganization() : null;

            return response()->json([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'role' => $user->role,
                        'phone' => $user->phone,
                        'unique_id' => $user->unique_id,
                        'is_active' => $user->is_active,
                        'created_at' => $user->created_at,
                        'updated_at' => $user->updated_at,
                    ],
                    // Additive (docs/decisions.md D7): the client's business, null for staff.
                    'organization' => $organization ? [
                        'id' => $organization->ulid,
                        'name' => $organization->name,
                        'status' => $organization->status->value,
                        'timezone' => $organization->timezone,
                        'currency' => $organization->currency,
                    ] : null,
                ],
            ], 200);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get user data',
            ], 500);
        }
    }

    /**
     * Refresh token
     *
     * @return JsonResponse
     */
    public function refresh(Request $request)
    {
        try {
            $user = $request->user();

            // Revoke current token
            $request->user()->currentAccessToken()->delete();

            // Create new token
            $token = $user->createToken('auth-token')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Token refreshed successfully',
                'data' => [
                    'token' => $token,
                    'token_type' => 'Bearer',
                ],
            ], 200);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Token refresh failed',
            ], 500);
        }
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Account\TwoFactor;
use App\Support\Audit\Audit;
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
                'device_name' => 'nullable|string|max:100',
                'two_factor_code' => 'nullable|string|max:20',
            ]);

            $user = User::where('email', $request->email)->first();

            if (! $user || ! Hash::check($request->password, $user->password)) {
                app(Audit::class)->record('auth.login_failed', $user, new: ['channel' => 'api', 'email' => $request->email], actor: $user);

                return response()->json([
                    'success' => false,
                    'message' => 'Invalid credentials',
                ], 401);
            }

            if (! $user->is_active) {
                app(Audit::class)->record('auth.login_blocked', $user, new: ['channel' => 'api', 'reason' => 'deactivated'], actor: $user);

                return response()->json([
                    'success' => false,
                    'account_deactivated' => true,
                    'message' => 'Your account has been deactivated. Please contact the concerned person for assistance.',
                ], 403);
            }

            // Two-step sign-in (D8, D24): the app sends the code with the password.
            if ($user->hasTwoFactor()) {
                $code = (string) $request->input('two_factor_code', '');
                if ($code === '') {
                    return response()->json(['success' => false, 'two_factor_required' => true, 'message' => 'Enter the 6-digit code from your authenticator app.'], 401);
                }
                if (! app(TwoFactor::class)->check($user, $code)) {
                    app(Audit::class)->record('auth.login_failed', $user, new: ['channel' => 'api', 'reason' => 'two_factor'], actor: $user);

                    return response()->json(['success' => false, 'two_factor_required' => true, 'message' => 'That code isn\'t right. Try the current one from your app.'], 401);
                }
            } elseif ($user->requiresTwoFactor()) {
                app(Audit::class)->record('auth.login_blocked', $user, new: ['channel' => 'api', 'reason' => 'two_factor_not_set_up'], actor: $user);

                return response()->json(['success' => false, 'two_factor_setup_required' => true, 'message' => 'Set up two-step sign-in on the SureHelp website first, then sign in to the app.'], 403);
            }

            // One named token per device, so users can see and revoke their devices (docs/decisions.md D8).
            $deviceName = trim((string) $request->input('device_name')) ?: 'Mobile app';
            $newToken = $user->createToken($deviceName, ['*'], self::tokenExpiry());
            $token = $newToken->plainTextToken;
            app(Audit::class)->record('auth.login', $user, new: ['channel' => 'api', 'device' => $deviceName, 'method' => $user->hasTwoFactor() ? 'two_factor' : 'password'], actor: $user);
            $user->forceFill(['last_login_at' => now()])->saveQuietly();

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
                    'expires_at' => $newToken->accessToken->expires_at?->toIso8601String(),
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
            app(Audit::class)->record('auth.logout', $request->user(), new: ['channel' => 'api']);

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
            $current = $user->currentAccessToken();
            // Keep the device name, so the device list stays meaningful after a refresh.
            $deviceName = $current->name ?? 'Mobile app';

            // Revoke current token, then issue its replacement.
            $current->delete();
            $newToken = $user->createToken($deviceName, ['*'], self::tokenExpiry());
            app(Audit::class)->record('auth.token_refreshed', $user, new: ['channel' => 'api', 'device' => $deviceName], actor: $user);

            return response()->json([
                'success' => true,
                'message' => 'Token refreshed successfully',
                'data' => [
                    'token' => $newToken->plainTextToken,
                    'token_type' => 'Bearer',
                    'expires_at' => $newToken->accessToken->expires_at?->toIso8601String(),
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

    private static function tokenExpiry(): ?\DateTimeInterface
    {
        $minutes = config('sanctum.expiration');

        return $minutes ? now()->addMinutes((int) $minutes) : null;
    }
}

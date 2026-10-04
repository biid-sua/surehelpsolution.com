<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects API requests from deactivated accounts and revokes the token used,
 * so deactivating a user takes effect on the mobile app immediately.
 */
class EnsureApiUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            $token = $user->currentAccessToken();

            // Cookie-authenticated (SPA) requests carry a TransientToken, which can't be deleted.
            // @phpstan-ignore instanceof.alwaysTrue
            if ($token instanceof PersonalAccessToken) {
                $token->delete();
            }

            return response()->json([
                'success' => false,
                'account_deactivated' => true,
                'message' => 'Your account has been deactivated. Please contact the concerned person for assistance.',
            ], 403);
        }

        return $next($request);
    }
}

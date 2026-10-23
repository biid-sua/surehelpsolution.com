<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A business's API key (D87) on "Authorization: Bearer shk_…". The key acts as the person who made it,
 * with their permissions, and only on the business API (/api/v1/client/…). Read-only keys can't
 * change anything. Other bearer tokens go on to Sanctum as before.
 */
class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->bearerToken();
        if (! str_starts_with($token, ApiKey::PREFIX)) {
            return $next($request);
        }

        $key = ApiKey::findActive($token);
        $user = $key?->user;
        $organization = $key ? Organization::query()->find($key->organization_id) : null;
        $deny = fn (string $message, int $code) => response()->json(['success' => false, 'message' => $message], $code);
        if (! $key || ! $user || ! $user->is_active || ! $organization || $user->activeOrganization($organization->ulid)?->id !== $organization->id) {
            return $deny('Unauthenticated.', 401);
        }
        if (! $request->is('api/v1/client/*')) {
            return $deny('API keys work only on the business API (/api/v1/client/…).', 403);
        }
        if (! $key->can_write && ! $request->isMethodSafe()) {
            return $deny('This API key is read-only.', 403);
        }

        Auth::guard('sanctum')->setUser($user);
        Auth::setUser($user);
        $request->attributes->set('api_key', $key);
        if (! $key->last_used_at || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}

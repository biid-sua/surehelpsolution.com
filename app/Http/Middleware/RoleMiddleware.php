<?php

namespace App\Http\Middleware;

use App\Exceptions\AuthorizationFailureLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        if (! auth()->check()) {
            // Check if this is an API request
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            return redirect()->route('home')->with('error', 'Please login to access this page.');
        }

        $user = auth()->user();

        if (! in_array($user->role, $roles)) {
            // Check if this is an API request
            if ($request->expectsJson() || $request->is('api/*')) {
                // Answered directly (not thrown), so log the denial here (spec §61).
                AuthorizationFailureLogger::log($request, 'role '.implode('|', $roles).' required');

                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. '.implode(' or ', $roles).' role required.',
                ], 403);
            }

            abort(403, 'Unauthorized access.');
        }

        return $next($request);
    }
}

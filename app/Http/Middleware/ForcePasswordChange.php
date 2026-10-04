<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForcePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if ($user && $user->requiresPasswordChange()) {
            $allowedRoutes = [
                'password.change',
                'password.change.update',
                'auth.logout',
            ];

            if (! $request->routeIs($allowedRoutes)) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You must change your password before continuing.',
                        'must_change_password' => true,
                        'redirect' => route('password.change'),
                    ], 403);
                }

                return redirect()->route('password.change');
            }
        }

        return $next($request);
    }
}

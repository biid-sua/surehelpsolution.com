<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the tenant context for client-portal requests from the user's own
 * membership. The browser never chooses the organization.
 */
class ResolveOrganization
{
    public function __construct(private readonly CurrentOrganization $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isClient()) {
            $organization = $user->primaryOrganization();

            if (! $organization) {
                $message = 'Your account is not linked to a business yet. Please contact support.';

                return $request->expectsJson() || $request->is('api/*')
                    ? response()->json(['success' => false, 'message' => $message], 403)
                    : abort(403, $message);
            }

            $this->current->set($organization);
        }

        return $next($request);
    }
}

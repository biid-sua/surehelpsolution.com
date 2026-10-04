<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Structured log of denied requests (spec §61) in the "security" channel.
 *
 * Laravel never "reports" authorization exceptions, so this hooks in as a render
 * callback and always returns null, leaving the actual response unchanged.
 */
class AuthorizationFailureLogger
{
    public function __invoke(Throwable $e, Request $request): null
    {
        $denied = $e instanceof AuthorizationException
            || $e instanceof AccessDeniedHttpException
            || ($e instanceof HttpException && $e->getStatusCode() === 403);

        if ($denied) {
            self::log($request, $e->getMessage() ?: null);
        }

        return null;
    }

    /**
     * Also used by middleware that answers 403 itself instead of throwing.
     */
    public static function log(Request $request, ?string $reason = null): void
    {
        Log::channel('security')->warning('authorization.denied', [
            'user_id' => $request->user()?->getAuthIdentifier(),
            'role' => $request->user()?->role,
            'method' => $request->method(),
            'path' => '/'.ltrim($request->path(), '/'),
            'route' => $request->route()?->getName(),
            'ip' => $request->ip(),
            'reason' => $reason,
        ]);
    }
}

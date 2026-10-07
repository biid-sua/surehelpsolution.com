<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Safe retries for the mobile API's create endpoints (D52). A request carrying an `Idempotency-Key`
 * header is done once per user and key for 24 hours: a retry gets the first answer back (marked
 * `Idempotent-Replayed: true`) instead of creating a second call log, booking or task.
 * The same key with a different request body is refused (422); a retry while the first is still
 * running gets 409. Server errors aren't remembered, so they can be retried. Without the header,
 * nothing changes.
 */
class Idempotent
{
    public const TTL_SECONDS = 86400;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        if ($key === null || $request->user() === null) {
            return $next($request);
        }
        if (! preg_match('/^[A-Za-z0-9_\-:.]{8,100}$/', $key)) {
            return ApiResponse::error('The Idempotency-Key header must be 8–100 letters, digits, dashes, dots, colons or underscores.', 422);
        }

        $cacheKey = 'idempotency:'.$request->user()->getAuthIdentifier().':'.sha1($request->method().' '.$request->path().' '.$key);
        $fingerprint = sha1((string) json_encode($request->all()));

        if ($stored = Cache::get($cacheKey)) {
            return $this->replay($stored, $fingerprint);
        }

        $lock = Cache::lock($cacheKey.':lock', 30);
        if (! $lock->get()) {
            return ApiResponse::error('The same request is still being handled. Try again in a moment.', 409);
        }

        try {
            // Another attempt may have finished while we waited for the lock.
            if ($stored = Cache::get($cacheKey)) {
                return $this->replay($stored, $fingerprint);
            }

            $response = $next($request);
            if ($response->getStatusCode() < 500) {
                Cache::put($cacheKey, [
                    'fingerprint' => $fingerprint,
                    'status' => $response->getStatusCode(),
                    'body' => $response->getContent(),
                    'type' => $response->headers->get('Content-Type', 'application/json'),
                ], self::TTL_SECONDS);
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    /** @param  array{fingerprint: string, status: int, body: string, type: string}  $stored */
    private function replay(array $stored, string $fingerprint): Response
    {
        if (! hash_equals($stored['fingerprint'], $fingerprint)) {
            return ApiResponse::error('This Idempotency-Key was already used for a different request.', 422);
        }

        return new Response($stored['body'], $stored['status'], ['Content-Type' => $stored['type'], 'Idempotent-Replayed' => 'true']);
    }
}

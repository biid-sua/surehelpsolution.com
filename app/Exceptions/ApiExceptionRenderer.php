<?php

namespace App\Exceptions;

use App\Http\Responses\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Turns any exception thrown under /api into the standard envelope (spec §49, §60):
 * correct status code, plain-language message, no internals.
 */
class ApiExceptionRenderer
{
    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null; // web pages keep Laravel's own error pages
        }

        if ($e instanceof HttpResponseException) {
            return null; // already carries a finished response (e.g. Form Request failures)
        }

        return match (true) {
            $e instanceof ValidationException => ApiResponse::error('Validation failed', 422, $e->errors()),
            $e instanceof AuthenticationException => ApiResponse::error('Unauthenticated.', 401),
            // Messages here are ours (policies, abort(403, …)); default kept identical to Laravel's for app compatibility.
            $e instanceof AuthorizationException, $e instanceof AccessDeniedHttpException => ApiResponse::error($e->getMessage() ?: 'This action is unauthorized.', 403),
            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => ApiResponse::error('Not found.', 404),
            $e instanceof MethodNotAllowedHttpException => ApiResponse::error('Method not allowed.', 405),
            $e instanceof ThrottleRequestsException => ApiResponse::error(
                'Too many requests. Please slow down and try again shortly.', 429,
                extra: ['retry_after' => (int) ($e->getHeaders()['Retry-After'] ?? 60)],
            )->withHeaders($e->getHeaders()),
            $e instanceof HttpExceptionInterface => ApiResponse::error($this->httpMessage($e->getStatusCode()), $e->getStatusCode())->withHeaders($e->getHeaders()),
            default => ApiResponse::error('Something went wrong on our side. Please try again.', 500),
        };
    }

    private function httpMessage(int $status): string
    {
        return match ($status) {
            400 => 'Bad request.',
            409 => 'This conflicts with the current state.',
            413 => 'The request is too large.',
            419 => 'Your session expired. Please sign in again.',
            503 => 'We are doing maintenance. Please try again shortly.',
            default => $status >= 500 ? 'Something went wrong on our side. Please try again.' : 'The request could not be completed.',
        };
    }
}

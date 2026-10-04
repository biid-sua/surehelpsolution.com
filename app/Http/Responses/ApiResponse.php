<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

/**
 * The one response envelope for /api (spec §49, docs/api.md):
 *
 *   { "success": true,  "data": {...}, "message": "..." }
 *   { "success": false, "message": "...", "errors": { "field": ["..."] } }
 *
 * Error responses never carry exception messages or stack traces.
 */
class ApiResponse
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public static function success(mixed $data = null, ?string $message = null, int $status = 200, array $meta = []): JsonResponse
    {
        return response()->json(array_filter([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => $meta ?: null,
        ], fn ($value) => $value !== null), $status);
    }

    /**
     * @param  array<string, mixed>|null  $errors
     * @param  array<string, mixed>  $extra  additive keys (e.g. retry_after)
     */
    public static function error(string $message, int $status, ?array $errors = null, array $extra = []): JsonResponse
    {
        return response()->json(array_filter([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ] + $extra, fn ($value) => $value !== null), $status);
    }
}

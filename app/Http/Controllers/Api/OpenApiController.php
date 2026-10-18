<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Api\OpenApiSpec;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * The OpenAPI description of /api/v1 (API-19), for generating or checking the app's client code.
 */
class OpenApiController extends Controller
{
    public function __invoke(OpenApiSpec $spec): JsonResponse
    {
        $document = Cache::remember('api.openapi.'.md5((string) config('app.url')), now()->addHour(), fn () => $spec->build());

        return response()->json($document, 200, ['Cache-Control' => 'public, max-age=3600'], JSON_UNESCAPED_SLASHES);
    }
}

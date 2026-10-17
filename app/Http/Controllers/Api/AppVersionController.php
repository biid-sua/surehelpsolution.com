<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The mobile app asks on launch whether it must or may update (API-5). No sign-in needed, so a build
 * too old to sign in can still be told to update.
 */
class AppVersionController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'platform' => ['required', Rule::in(['ios', 'android'])],
            'version' => ['nullable', 'string', 'regex:/^\d+(\.\d+){0,3}$/'],
        ]);
        $config = (array) config('mobile.'.$data['platform']);
        $version = $data['version'] ?? null;

        return ApiResponse::success([
            'platform' => $data['platform'],
            'minimum' => (string) $config['minimum'],
            'latest' => (string) $config['latest'],
            'store_url' => $config['store_url'] ?: null,
            'update_required' => $version !== null && version_compare($version, (string) $config['minimum'], '<'),
            'update_available' => $version !== null && version_compare($version, (string) $config['latest'], '<'),
        ]);
    }
}

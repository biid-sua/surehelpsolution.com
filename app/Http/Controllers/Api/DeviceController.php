<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeviceTokenResource;
use App\Http\Responses\ApiResponse;
use App\Support\Audit\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Signed-in devices for the current user (spec §71, docs/decisions.md D8).
 */
class DeviceController extends Controller
{
    public function __construct(private readonly Audit $audit) {}

    public function index(Request $request): JsonResponse
    {
        $tokens = $request->user()->tokens()
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest('last_used_at')->latest('id')
            ->get();

        return ApiResponse::success(['devices' => DeviceTokenResource::collection($tokens)->resolve($request)]);
    }

    /**
     * Sign out one device. Only the user's own tokens can be found (404 otherwise).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        /** @var PersonalAccessToken $token */
        $token = $request->user()->tokens()->whereKey($id)->firstOrFail();
        $token->delete();

        $this->audit->record('auth.token_revoked', $request->user(), new: ['device' => $token->name]);

        return ApiResponse::success(message: 'Device signed out.');
    }

    /**
     * "Sign out everywhere else": revokes every token except the one making this request.
     */
    public function destroyOthers(Request $request): JsonResponse
    {
        $current = $request->user()->currentAccessToken();
        $query = $request->user()->tokens();

        // Cookie-authenticated (SPA) requests carry a TransientToken, not a stored one.
        // @phpstan-ignore instanceof.alwaysTrue
        if ($current instanceof PersonalAccessToken) {
            $query->whereKeyNot($current->getKey());
        }

        $count = $query->delete();
        $this->audit->record('auth.tokens_revoked', $request->user(), new: ['count' => $count, 'scope' => 'other_devices']);

        return ApiResponse::success(['revoked' => $count], 'Signed out of all other devices.');
    }
}

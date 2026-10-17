<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Billing\Entitlements;
use App\Services\Billing\FeatureAccess;
use App\Services\Privacy\AccountClosure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the signed-in person may use (API-1), so the mobile app shows only what works for them:
 * their permissions (in their business for owners and their team), the business's features and plan
 * limits, and the state of their account.
 */
class CapabilitiesController extends Controller
{
    /** Plan limits the app shows or enforces. */
    private const LIMITS = ['calls', 'team_members', 'calendars', 'social_accounts', 'social_ai_tokens', 'seo_ai_tokens'];

    public function __invoke(Request $request, FeatureAccess $features, Entitlements $entitlements): JsonResponse
    {
        $user = $request->user();
        $organization = $user->isClient() ? $user->primaryOrganization() : null;

        $permissions = array_values(array_filter((array) config('authorization.permissions', []),
            fn (string $p) => $user->hasPermissionIn($p, $organization)));

        $business = null;
        if ($organization) {
            $subscription = $entitlements->subscription($organization);
            $business = [
                'id' => $organization->ulid,
                'name' => $organization->name,
                'status' => $organization->status->value,
                'serving' => $organization->isServing(),
                'role' => $user->organizationRole($organization),
                'is_owner' => $organization->owner_user_id === $user->id,
                'features' => collect(array_keys($features->catalog()))->mapWithKeys(fn (string $f) => [$f => $features->allows($organization, $f)])->all(),
                'limits' => collect(self::LIMITS)->mapWithKeys(fn (string $l) => [$l => $entitlements->limit($organization, $l)])->all(),
                'plan' => $subscription ? [
                    'name' => $subscription->plan->name,
                    'status' => $subscription->status->value,
                    'trial_ends_on' => $subscription->trial_ends_on?->toDateString(),
                    'current_period_end' => $subscription->current_period_end->toDateString(),
                ] : null,
                'closes_at' => $organization->closes_at?->toIso8601String(),
            ];
        }

        return ApiResponse::success([
            'role' => $user->role,
            'permissions' => $permissions,
            'business' => $business,
            'account' => [
                'email_verified' => $user->hasVerifiedEmail(),
                'two_factor_enabled' => $user->hasTwoFactor(),
                'two_factor_required' => $user->requiresTwoFactor(),
                'can_close_account' => AccountClosure::canRequest($user, $organization),
            ],
        ]);
    }
}

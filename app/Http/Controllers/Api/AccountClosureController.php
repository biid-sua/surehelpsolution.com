<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Privacy\AccountClosure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Closing an account from the mobile app (API-4; Apple App Store 5.1.1(v), Google Play). The same rules
 * as the web (D29): the business owner confirms with the business name and their password, the account
 * closes after a 30-day grace period and can be kept until then. Everyone else is told whom to ask.
 */
class AccountClosureController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success($this->state($request));
    }

    public function store(Request $request, AccountClosure $closure): JsonResponse
    {
        $user = $request->user();
        $organization = $user->isClient() ? $user->primaryOrganization() : null;
        if (! AccountClosure::canRequest($user, $organization)) {
            return ApiResponse::error($this->whoToAsk($request), 403);
        }
        $data = $request->validate(['business_name' => ['required', 'string', 'max:255'], 'password' => ['required', 'string']]);

        // The password check must not become a way to guess passwords.
        $key = 'close-account:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return ApiResponse::error('Too many tries. Wait '.ceil(RateLimiter::availableIn($key) / 60).' minutes.', 429, extra: ['retry_after' => RateLimiter::availableIn($key)]);
        }
        RateLimiter::hit($key, 600);

        if ($organization->closes_at) {
            return ApiResponse::error('Closing is already scheduled for '.$organization->closes_at->format('M j, Y').'.', 409);
        }
        try {
            $closure->request($organization, $user, $data['business_name'], $data['password']);
        } catch (ValidationException $e) {
            $errors = $e->errors();

            return ApiResponse::error('Please check the details.', 422, array_filter([
                'business_name' => $errors['confirmName'] ?? [],
                'password' => $errors['password'] ?? [],
            ]));
        }
        RateLimiter::clear($key);

        return ApiResponse::success($this->state($request), 'Your account will close on '.$organization->fresh()->closes_at->format('M j, Y').'. We\'ve emailed you the details.');
    }

    public function destroy(Request $request, AccountClosure $closure): JsonResponse
    {
        $user = $request->user();
        $organization = $user->isClient() ? $user->primaryOrganization() : null;
        if (! AccountClosure::canRequest($user, $organization)) {
            return ApiResponse::error($this->whoToAsk($request), 403);
        }
        if ($organization->closes_at) {
            $closure->cancel($organization, $user);
        }

        return ApiResponse::success($this->state($request), 'Your account stays open. Nothing was deleted.');
    }

    /** @return array<string, mixed> */
    private function state(Request $request): array
    {
        $user = $request->user();
        $organization = $user->isClient() ? $user->primaryOrganization()?->fresh() : null;
        $can = AccountClosure::canRequest($user, $organization);

        return [
            'can_request' => $can,
            'business_name' => $can ? $organization->name : null,
            'grace_days' => AccountClosure::GRACE_DAYS,
            'scheduled' => (bool) $organization?->closes_at,
            'requested_at' => $organization?->closure_requested_at?->toIso8601String(),
            'closes_at' => $organization?->closes_at?->toIso8601String(),
            'message' => $can ? null : $this->whoToAsk($request),
            'contact_email' => $can ? null : (string) config('company.email'),
        ];
    }

    private function whoToAsk(Request $request): string
    {
        return $request->user()->isClient()
            ? 'Only the business owner can close this account. Ask them, or email '.config('company.email').' to have your own login removed.'
            : 'Your account is managed by SureHelp. Email '.config('company.email').' to have it closed.';
    }
}

<?php

namespace App\Http\Middleware;

use App\Services\Billing\FeatureAccess;
use App\Support\Tenancy\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `feature:calendar_sync` on a business-portal route: the current business must have the paid
 * feature (D46). Pages send people to Billing to see their options; the API answers 403.
 */
class RequireFeature
{
    public function __construct(
        private readonly CurrentOrganization $current,
        private readonly FeatureAccess $features,
    ) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $organization = $this->current->get();
        if ($organization && $this->features->allows($organization, $feature)) {
            return $next($request);
        }

        $message = $this->features->label($feature).' isn\'t included in your plan.';
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['success' => false, 'message' => $message, 'feature' => $feature], 403);
        }

        return $request->user()?->can('billing.view', $organization)
            ? redirect()->route('app.billing')->with('feature_locked', $message.' See the plans and add-ons below, or contact us.')
            : redirect()->route('app.dashboard')->with('feature_locked', $message.' Ask the business owner about adding it.');
    }
}

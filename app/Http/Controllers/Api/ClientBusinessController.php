<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\BusinessHoliday;
use App\Models\BusinessProfile;
use App\Models\BusinessService;
use App\Services\Business\BusinessHours;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/client/business — profile, primary location, hours and open status (docs/api.md).
 */
class ClientBusinessController extends Controller
{
    /**
     * GET /api/v1/client/services — the business's services; inactive ones only with ?include_inactive=1.
     */
    public function services(Request $request, CurrentOrganization $current): JsonResponse
    {
        $organization = $current->get();
        abort_if($organization === null, 403);

        $services = BusinessService::query()->forOrganization($organization)
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')->orderBy('name')
            ->get()
            ->map(fn (BusinessService $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'description' => $s->description,
                'category' => $s->category,
                'duration_minutes' => $s->duration_minutes,
                'buffer_minutes' => $s->buffer_minutes,
                'price_type' => $s->price_type->value,
                'price_cents' => $s->price_cents,
                'currency' => $s->currency,
                'price_label' => $s->priceLabel(),
                'is_active' => $s->is_active,
                'is_bookable' => $s->is_bookable,
                'required_fields' => $s->required_fields ?? [],
            ]);

        return ApiResponse::success(['services' => $services]);
    }

    public function show(CurrentOrganization $current, BusinessHours $hours): JsonResponse
    {
        $organization = $current->get();
        abort_if($organization === null, 403);

        $profile = BusinessProfile::query()->forOrganization($organization)->first();
        $location = $organization->primaryLocation()->first();
        $status = $hours->status($organization);
        $today = now($organization->timezoneOrDefault())->toDateString();

        return ApiResponse::success([
            'business' => [
                'id' => $organization->ulid,
                'name' => $organization->name,
                'timezone' => $organization->timezone,
                'currency' => $organization->currency,
                'legal_name' => $profile?->legal_name,
                'description' => $profile?->description,
                'business_type' => $profile?->business_type,
                'industry' => $profile?->industry,
                'website' => $profile?->website,
                'phone' => $profile?->phone,
                'email' => $profile?->email,
                'service_area' => $profile?->service_area,
                'emergency_available' => (bool) $profile?->emergency_available,
                'closed_until' => $profile?->closed_until?->toDateString(),
                'closure_message' => $profile?->closure_message,
            ],
            'location' => $location ? [
                'name' => $location->name,
                'address_line1' => $location->address_line1,
                'address_line2' => $location->address_line2,
                'city' => $location->city,
                'state' => $location->state,
                'postal_code' => $location->postal_code,
                'country' => $location->country,
                'formatted' => $location->singleLine(),
            ] : null,
            'hours' => $hours->weekly($organization),
            'upcoming_holidays' => BusinessHoliday::query()->forOrganization($organization)
                ->where('date', '>=', $today)->orderBy('date')->limit(10)->get()
                ->map(fn (BusinessHoliday $h) => [
                    'date' => $h->date->toDateString(),
                    'name' => $h->name,
                    'is_closed' => $h->is_closed,
                    'opens_at' => $h->opens_at ? substr($h->opens_at, 0, 5) : null,
                    'closes_at' => $h->closes_at ? substr($h->closes_at, 0, 5) : null,
                ])->values(),
            'status' => [
                'open' => $status['open'],
                'label' => $status['label'],
                'until' => $status['until']?->toIso8601String(),
                'next_open' => $status['next_open']?->toIso8601String(),
                'reason' => $status['reason'],
            ],
        ]);
    }
}

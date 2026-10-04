<?php

namespace App\Http\Controllers\Api;

use App\Enums\CustomerStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\TimelineEventResource;
use App\Http\Responses\ApiResponse;
use App\Models\Customer;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customers for the mobile app (docs/api.md). Scoped to the caller's business.
 */
class CustomerController extends Controller
{
    public function index(Request $request, CurrentOrganization $current): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = Customer::query()->forOrganization($current->get())
            ->with('tags:id,name')
            ->search((string) $request->query('search', ''))
            ->when(CustomerStatus::tryFrom((string) $request->query('status')), fn (Builder $q, CustomerStatus $s) => $q->where('status', $s))
            ->orderByRaw('last_activity_at IS NULL')->orderByDesc('last_activity_at')->orderByDesc('id')
            ->paginate((int) $request->query('per_page', 25));

        return ApiResponse::success(
            ['customers' => CustomerResource::collection($page->getCollection())->resolve($request)],
            meta: ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        );
    }

    public function show(Request $request, CurrentOrganization $current, string $ulid): JsonResponse
    {
        $customer = Customer::query()->forOrganization($current->get())->where('ulid', $ulid)->with('tags:id,name')->firstOrFail();

        return ApiResponse::success([
            'customer' => (new CustomerResource($customer))->resolve($request),
            'timeline' => TimelineEventResource::collection($customer->timeline()->limit(50)->get())->resolve($request),
        ]);
    }
}

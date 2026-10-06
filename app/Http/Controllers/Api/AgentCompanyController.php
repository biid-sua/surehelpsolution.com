<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Http\Responses\ApiResponse;
use App\Models\AgentAssignment;
use App\Models\Appointment;
use App\Models\CallLog;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Companies for the agent mobile app (spec §20A, brief §8–§2.9): exactly the same rule as the web
 * portal, the user's current assignment checked through the one permission check. An unassigned
 * company and a company that doesn't exist get the same 404.
 */
class AgentCompanyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $companies = $user->workableOrganizations()->orderBy('name')->get(['id', 'ulid', 'name', 'timezone', 'status']);
        $assignments = AgentAssignment::query()->where('agent_user_id', $user->id)->current()->get()->keyBy('organization_id');

        return ApiResponse::success(['companies' => $companies->map(fn (Organization $o) => [
            'id' => $o->ulid,
            'name' => $o->name,
            'timezone' => $o->timezoneOrDefault(),
            'assignment' => ($a = $assignments->get($o->id)) ? [
                'status' => $a->effectiveStatus(),
                'type' => $a->assignment_type,
                'starts_at' => $a->starts_at?->toIso8601String(),
                'ends_at' => $a->ends_at?->toIso8601String(),
            ] : null,
        ])->values()]);
    }

    public function show(Request $request, string $company): JsonResponse
    {
        $organization = $this->company($request, $company, 'organization.view');
        $timezone = $organization->timezoneOrDefault();
        $today = [CarbonImmutable::now($timezone)->startOfDay()->utc(), CarbonImmutable::now($timezone)->endOfDay()->utc()];

        return ApiResponse::success(['company' => [
            'id' => $organization->ulid,
            'name' => $organization->name,
            'timezone' => $timezone,
            'appointments_today' => Appointment::query()->forOrganization($organization)->blocking()->whereBetween('starts_at', $today)->count(),
        ]]);
    }

    public function customers(Request $request, string $company): JsonResponse
    {
        $organization = $this->company($request, $company, 'customers.view');
        $request->validate(['search' => ['nullable', 'string', 'max:100'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $page = Customer::query()->forOrganization($organization)->search((string) $request->query('search', ''))
            ->orderByDesc('last_activity_at')->orderByDesc('id')->paginate((int) $request->query('per_page', 25));

        return $this->paged('customers', CustomerResource::collection($page->getCollection())->resolve($request), $page);
    }

    public function customer(Request $request, string $company, string $customer): JsonResponse
    {
        $organization = $this->company($request, $company, 'customers.view');
        // Looked up inside the company: another company's customer id is simply "not found".
        $model = Customer::query()->forOrganization($organization)->where('ulid', $customer)->first() ?? throw new NotFoundHttpException;

        return ApiResponse::success(['customer' => (new CustomerResource($model))->resolve($request)]);
    }

    public function appointments(Request $request, string $company): JsonResponse
    {
        $organization = $this->company($request, $company, 'appointments.view');
        $timezone = $organization->timezoneOrDefault();
        $from = CarbonImmutable::now($timezone)->startOfDay();

        return ApiResponse::success(['appointments' => Appointment::query()->forOrganization($organization)
            ->whereBetween('starts_at', [$from->utc(), $from->addDays(14)->utc()])->with(['customer:id,ulid,first_name,last_name,company', 'service:id,name'])
            ->orderBy('starts_at')->limit(200)->get()->map(fn (Appointment $a) => [
                'id' => $a->ulid,
                'title' => $a->title,
                'status' => $a->status->value,
                'starts_at' => $a->starts_at->toIso8601String(),
                'local_start' => $a->starts_at->setTimezone($timezone)->format('Y-m-d H:i'),
                'service' => $a->service?->name,
                'customer' => $a->customer ? ['id' => $a->customer->ulid, 'name' => $a->customer->fullName()] : null,
            ])->values()]);
    }

    public function calls(Request $request, string $company): JsonResponse
    {
        $organization = $this->company($request, $company, 'calls.view');
        $page = CallLog::query()->forOrganization($organization)->latest('created_at')->paginate(min(100, (int) $request->query('per_page', 25)));

        return $this->paged('calls', $page->getCollection()->map(fn (CallLog $c) => [
            'call_id' => $c->call_id,
            'caller_name' => $c->caller_name,
            'reason' => $c->reason_for_call,
            'outcome' => $c->call_outcome,
            'status_label' => $c->statusLabel(),
            'created_at' => $c->created_at?->toIso8601String(),
        ])->values()->all(), $page);
    }

    public function conversations(Request $request, string $company): JsonResponse
    {
        $organization = $this->company($request, $company, 'messages.view');

        return ApiResponse::success(['conversations' => Conversation::query()->forOrganization($organization)->where('status', 'open')
            ->orderByDesc('last_message_at')->limit(50)->get()->map(fn (Conversation $c) => [
                'id' => $c->ulid,
                'channel' => $c->channel->value,
                'name' => $c->displayName(),
                'needs_human' => $c->needs_human,
                'last_message_at' => $c->last_message_at?->toIso8601String(),
            ])->values()]);
    }

    /**
     * The one check (D40): the company exists and the user holds the permission there, which for agents
     * means a current assignment. Otherwise "not found", whether it exists or not.
     */
    private function company(Request $request, string $ulid, string $permission): Organization
    {
        $organization = Organization::query()->where('ulid', $ulid)->first();
        if (! $organization || ! $request->user()->hasPermissionIn($permission, $organization)) {
            throw new NotFoundHttpException;
        }

        return $organization;
    }

    /**
     * @param  list<mixed>|array<mixed>  $items
     * @param  LengthAwarePaginator<int, mixed>  $page
     */
    private function paged(string $key, array $items, LengthAwarePaginator $page): JsonResponse
    {
        return ApiResponse::success([$key => $items], meta: ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]);
    }
}

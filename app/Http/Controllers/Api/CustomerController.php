<?php

namespace App\Http\Controllers\Api;

use App\Actions\Customers\CreateCustomer;
use App\Actions\Customers\DuplicateCustomer;
use App\Actions\Customers\MatchOrCreateCustomer;
use App\Actions\Customers\RecordTimelineEvent;
use App\Actions\Customers\UpdateCustomer;
use App\Enums\CustomerStatus;
use App\Enums\TimelineEventType;
use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\TimelineEventResource;
use App\Http\Responses\ApiResponse;
use App\Models\Customer;
use App\Models\Tag;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Customers for the mobile app (docs/api.md). Scoped to the caller's business. Adding and editing
 * (API-8) use the same actions and duplicate checks as the web.
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

    /** Is there already a customer with this number or email? For warning while someone types (API-8). */
    public function lookup(Request $request, CurrentOrganization $current, MatchOrCreateCustomer $customers): JsonResponse
    {
        $data = $request->validate(['phone' => ['nullable', 'string', 'max:40'], 'email' => ['nullable', 'string', 'max:255']]);
        $match = $customers->lookup($current->get(), $data['phone'] ?? null, $data['email'] ?? null);

        return ApiResponse::success(['match' => $match ? ['id' => $match['customer']->ulid, 'name' => $match['customer']->fullName(), 'matched_by' => $match['matched_by']] : null]);
    }

    public function store(Request $request, CurrentOrganization $current, CreateCustomer $create): JsonResponse
    {
        $data = $request->validate(CreateCustomer::rules(), CreateCustomer::messages());
        try {
            $customer = $create->handle($current->get(), $data, $request->user());
        } catch (DuplicateCustomer $e) {
            return ApiResponse::error($e->getMessage(), 409, ['phone' => [$e->getMessage()]], ['existing' => ['id' => $e->existing->ulid, 'name' => $e->existing->fullName()]]);
        }

        return ApiResponse::success(['customer' => (new CustomerResource($customer->load('tags:id,name')))->resolve($request)], 'Customer added.', 201);
    }

    /** Change any of the fields; the ones not sent keep their value. */
    public function update(Request $request, CurrentOrganization $current, UpdateCustomer $update, string $ulid): JsonResponse
    {
        $customer = $this->find($current, $ulid);
        $rules = UpdateCustomer::rules();
        $data = array_merge(UpdateCustomer::formFor($customer), $request->validate(array_map(fn (array $r) => ['sometimes', ...$r], $rules)));
        $data = validator($data, $rules)->validate();
        try {
            $update->handle($customer, $data, $request->user());
        } catch (ValidationException $e) {
            return ApiResponse::error(collect($e->errors())->flatten()->first() ?? 'Please check the details.', 422, $e->errors());
        }

        return ApiResponse::success(['customer' => (new CustomerResource($customer->fresh()->load('tags:id,name')))->resolve($request)], 'Customer updated.');
    }

    public function addNote(Request $request, CurrentOrganization $current, RecordTimelineEvent $timeline, string $ulid): JsonResponse
    {
        $customer = $this->find($current, $ulid);
        $note = trim($request->validate(['note' => ['required', 'string', 'max:5000']])['note']);
        $event = $timeline->handle($customer, TimelineEventType::NoteAdded, 'Note added', $note, actorId: $request->user()->id);

        return ApiResponse::success(['event' => (new TimelineEventResource($event))->resolve($request)], 'Note added.', 201);
    }

    public function addTag(Request $request, CurrentOrganization $current, string $ulid): JsonResponse
    {
        $customer = $this->find($current, $ulid);
        $name = trim($request->validate(['name' => ['required', 'string', 'max:50']])['name']);
        $tag = Tag::firstOrCreate(['organization_id' => $customer->organization_id, 'name' => $name]);
        $customer->tags()->syncWithoutDetaching([$tag->id]);

        return ApiResponse::success(['tags' => $customer->tags()->get(['tags.id', 'tags.name'])->map(fn (Tag $t) => ['id' => $t->id, 'name' => $t->name])->values()]);
    }

    public function removeTag(CurrentOrganization $current, string $ulid, int $tag): JsonResponse
    {
        $customer = $this->find($current, $ulid);
        $customer->tags()->detach($tag);

        return ApiResponse::success(['tags' => $customer->tags()->get(['tags.id', 'tags.name'])->map(fn (Tag $t) => ['id' => $t->id, 'name' => $t->name])->values()]);
    }

    private function find(CurrentOrganization $current, string $ulid): Customer
    {
        return Customer::query()->forOrganization($current->get())->where('ulid', $ulid)->firstOrFail();
    }
}

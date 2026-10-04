<?php

namespace App\Http\Controllers\Api;

use App\Actions\Escalations\AcknowledgeEscalation;
use App\Actions\Escalations\AssignEscalation;
use App\Actions\Escalations\ResolveEscalation;
use App\Enums\EscalationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\EscalationResource;
use App\Http\Responses\ApiResponse;
use App\Models\Escalation;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Escalations for the mobile app (docs/api.md, spec §25). Scoped to the caller's business.
 */
class EscalationController extends Controller
{
    private const RELATIONS = ['customer:id,ulid,first_name,last_name,company', 'call:id,call_id', 'assignee:id,name'];

    public function index(Request $request, CurrentOrganization $current): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::in(['active', 'resolved', 'all'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = Escalation::query()->forOrganization($current->get())
            ->with(self::RELATIONS)
            ->tap(fn (Builder $q) => match ($request->query('status', 'active')) {
                'resolved' => $q->where('status', EscalationStatus::Resolved->value)->latest('resolved_at')->latest('id'),
                'all' => $q->latest('id'),
                default => $q->active()->byUrgency(),
            })
            ->paginate((int) $request->query('per_page', 25));

        return ApiResponse::success(
            ['escalations' => EscalationResource::collection($page->getCollection())->resolve($request)],
            meta: ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        );
    }

    public function show(Request $request, CurrentOrganization $current, string $ulid): JsonResponse
    {
        return $this->respond($request, $this->find($current, $ulid));
    }

    public function acknowledge(Request $request, CurrentOrganization $current, string $ulid, AcknowledgeEscalation $acknowledge): JsonResponse
    {
        return $this->respond($request, $acknowledge->handle($this->find($current, $ulid), $request->user()), 'Escalation acknowledged');
    }

    public function assign(Request $request, CurrentOrganization $current, string $ulid, AssignEscalation $assign): JsonResponse
    {
        $data = $request->validate(['assigned_to' => ['present', 'nullable', 'integer']]);

        return $this->respond($request, $assign->handle($this->find($current, $ulid), $data['assigned_to'], $request->user()), 'Escalation assigned');
    }

    public function resolve(Request $request, CurrentOrganization $current, string $ulid, ResolveEscalation $resolve): JsonResponse
    {
        $data = $request->validate(['resolution_notes' => ['required', 'string', 'max:5000']]);

        return $this->respond($request, $resolve->handle($this->find($current, $ulid), $request->user(), $data['resolution_notes']), 'Escalation resolved');
    }

    private function find(CurrentOrganization $current, string $ulid): Escalation
    {
        return Escalation::query()->forOrganization($current->get())->where('ulid', $ulid)->firstOrFail();
    }

    private function respond(Request $request, Escalation $escalation, ?string $message = null): JsonResponse
    {
        return ApiResponse::success(['escalation' => (new EscalationResource($escalation->load(self::RELATIONS)))->resolve($request)], $message);
    }
}

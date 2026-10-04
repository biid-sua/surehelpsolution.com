<?php

namespace App\Http\Controllers\Api;

use App\Actions\Tasks\ChangeTaskStatus;
use App\Actions\Tasks\CreateTask;
use App\Actions\Tasks\UpdateTask;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Http\Responses\ApiResponse;
use App\Models\Customer;
use App\Models\Task;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Tasks and call-backs for the mobile app (docs/api.md, spec §24). Scoped to the caller's business.
 */
class TaskController extends Controller
{
    private const RELATIONS = ['customer:id,ulid,first_name,last_name,company', 'call:id,call_id', 'assignee:id,name'];

    public function index(Request $request, CurrentOrganization $current): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::in(['open', 'overdue', 'done', 'all'])],
            'mine' => ['nullable', 'boolean'],
            'customer' => ['nullable', 'string', 'max:26'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $organization = $current->get();
        $page = Task::query()->forOrganization($organization)
            ->with(self::RELATIONS)
            ->when($request->boolean('mine'), fn (Builder $q) => $q->where('assigned_to_user_id', $request->user()->id))
            ->when($request->query('customer'), fn (Builder $q, string $ulid) => $q->whereHas('customer', fn (Builder $c) => $c->where('ulid', $ulid)))
            ->tap(fn (Builder $q) => match ($request->query('status', 'open')) {
                'overdue' => $q->overdue()->byUrgency(),
                'done' => $q->whereIn('status', [TaskStatus::Completed->value, TaskStatus::Cancelled->value])->latest('updated_at')->latest('id'),
                'all' => $q->latest('id'),
                default => $q->open()->byUrgency(),
            })
            ->paginate((int) $request->query('per_page', 25));

        return ApiResponse::success(
            ['tasks' => TaskResource::collection($page->getCollection())->resolve($request)],
            meta: ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        );
    }

    public function show(Request $request, CurrentOrganization $current, string $ulid): JsonResponse
    {
        return ApiResponse::success(['task' => (new TaskResource($this->find($current, $ulid)))->resolve($request)]);
    }

    public function store(Request $request, CurrentOrganization $current, CreateTask $create): JsonResponse
    {
        $organization = $current->get();
        $data = $request->validate([
            'title' => ['required', 'string', 'max:250'],
            'type' => ['nullable', Rule::enum(TaskType::class)],
            'priority' => ['nullable', Rule::enum(TaskPriority::class)],
            'description' => ['nullable', 'string', 'max:5000'],
            'due_at' => ['nullable', 'date'],
            'customer_id' => ['nullable', 'string', 'max:26'],
            'assigned_to' => ['nullable', 'integer'],
        ]);

        $customer = null;
        if (! empty($data['customer_id'])) {
            $customer = Customer::query()->forOrganization($organization)->where('ulid', $data['customer_id'])->first()
                ?? throw ValidationException::withMessages(['customer_id' => ['Choose one of your customers.']]);
        }

        $task = $create->handle($organization, [
            'title' => $data['title'],
            'type' => $data['type'] ?? TaskType::Todo->value,
            'priority' => $data['priority'] ?? TaskPriority::Normal->value,
            'description' => $data['description'] ?? null,
            'due_at' => isset($data['due_at']) ? Carbon::parse($data['due_at'])->utc() : null,
            'customer_id' => $customer?->id,
            'assigned_to_user_id' => $data['assigned_to'] ?? null,
        ], $request->user());

        return ApiResponse::success(['task' => (new TaskResource($task->load(self::RELATIONS)))->resolve($request)], 'Task created', 201);
    }

    public function update(Request $request, CurrentOrganization $current, string $ulid, UpdateTask $update, ChangeTaskStatus $change): JsonResponse
    {
        $task = $this->find($current, $ulid);
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:250'],
            'type' => ['sometimes', Rule::enum(TaskType::class)],
            'priority' => ['sometimes', Rule::enum(TaskPriority::class)],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'assigned_to' => ['sometimes', 'nullable', 'integer'],
            'status' => ['sometimes', Rule::enum(TaskStatus::class)],
        ]);

        if (array_key_exists('due_at', $data)) {
            $data['due_at'] = $data['due_at'] !== null ? Carbon::parse($data['due_at'])->utc() : null;
        }
        if (array_key_exists('assigned_to', $data)) {
            $data['assigned_to_user_id'] = $data['assigned_to'];
        }

        $update->handle($task, $data, $request->user());

        if (isset($data['status'])) {
            $change->handle($task, TaskStatus::from($data['status']), $request->user());
        }

        return ApiResponse::success(['task' => (new TaskResource($task->fresh(self::RELATIONS)))->resolve($request)], 'Task updated');
    }

    private function find(CurrentOrganization $current, string $ulid): Task
    {
        return Task::query()->forOrganization($current->get())->where('ulid', $ulid)->with(self::RELATIONS)->firstOrFail();
    }
}

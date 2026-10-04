<?php

namespace App\Livewire\Client\Tasks;

use App\Actions\Tasks\ChangeTaskStatus;
use App\Actions\Tasks\CreateTask;
use App\Actions\Tasks\UpdateTask;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\Customer;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Call-backs, follow-ups and to-dos for the business's team (spec §24, CLI-04).
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Tasks')]
class Index extends Component
{
    use ScopedToOrganization;
    use WithPagination;

    public const VIEWS = ['open' => 'Open', 'mine' => 'Assigned to me', 'overdue' => 'Overdue', 'done' => 'Done'];

    #[Url(except: 'open')]
    public string $view = 'open';

    #[Url(except: '')]
    public string $search = '';

    /** Opened from a notification or link: ?task=<ulid>. */
    #[Url(as: 'task', except: '')]
    public string $focus = '';

    public bool $editing = false;

    /** ULID of the task being edited; null while creating. */
    public ?string $editingId = null;

    /** @var array<string, string> */
    public array $form = [];

    public function mount(): void
    {
        $organization = $this->organization();
        $this->authorize('tasks.view', $organization);
        $this->view = array_key_exists($this->view, self::VIEWS) ? $this->view : 'open';

        if ($this->focus !== '' && auth()->user()->can('tasks.update', $organization)
            && Task::query()->forOrganization($organization)->where('ulid', $this->focus)->exists()) {
            $this->edit($this->focus);
        }

        // "Add task" from a customer's page: /app/tasks?new=1&customer=<ulid>
        if (request()->boolean('new') && auth()->user()->can('tasks.create', $organization)) {
            $this->create((string) request()->query('customer', ''));
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['view', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function create(string $customer = ''): void
    {
        $this->authorize('tasks.create', $this->organization());
        $this->resetValidation();
        $this->editingId = null;
        $this->form = $this->blankForm();
        $this->form['customer'] = $customer !== '' && $this->customerByUlid($customer) ? $customer : '';
        $this->editing = true;
    }

    public function edit(string $ulid): void
    {
        $organization = $this->organization();
        $this->authorize('tasks.update', $organization);
        $task = $this->task($ulid);
        $due = $task->due_at?->setTimezone($organization->timezoneOrDefault());

        $this->resetValidation();
        $this->editingId = $task->ulid;
        $this->form = [
            'title' => $task->title,
            'type' => $task->type->value,
            'priority' => $task->priority->value,
            'due_date' => $due?->format('Y-m-d') ?? '',
            'due_time' => $due?->format('H:i') ?? '',
            'assigned_to_user_id' => (string) ($task->assigned_to_user_id ?? ''),
            'description' => (string) $task->description,
            'customer' => '',
        ];
        $this->editing = true;
    }

    public function save(CreateTask $create, UpdateTask $update): void
    {
        $organization = $this->organization();
        $this->authorize($this->editingId ? 'tasks.update' : 'tasks.create', $organization);

        $this->validate([
            'form.title' => ['required', 'string', 'max:250'],
            'form.type' => ['required', Rule::enum(TaskType::class)],
            'form.priority' => ['required', Rule::enum(TaskPriority::class)],
            'form.due_date' => ['nullable', 'date_format:Y-m-d', 'required_with:form.due_time'],
            'form.due_time' => ['nullable', 'date_format:H:i'],
            'form.assigned_to_user_id' => ['nullable', 'integer'],
            'form.description' => ['nullable', 'string', 'max:5000'],
        ], [
            'form.due_date.required_with' => 'Pick a day for this time.',
        ], ['form.title' => 'title', 'form.due_date' => 'due date', 'form.due_time' => 'time']);

        $data = [
            'title' => $this->form['title'],
            'type' => $this->form['type'],
            'priority' => $this->form['priority'],
            'due_at' => $this->dueAt(),
            'assigned_to_user_id' => $this->form['assigned_to_user_id'] !== '' ? (int) $this->form['assigned_to_user_id'] : null,
            'description' => $this->form['description'],
        ];

        try {
            if ($this->editingId) {
                $update->handle($this->task($this->editingId), $data, auth()->user());
                $message = 'Task updated.';
            } else {
                $data['customer_id'] = $this->form['customer'] !== '' ? $this->customerByUlid($this->form['customer'])?->id : null;
                $create->handle($organization, $data, auth()->user());
                $message = 'Task added.';
            }
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError('form.'.$field, $messages[0]);
            }

            return;
        }

        $this->editing = false;
        $this->focus = '';
        $this->dispatch('toast', type: 'success', message: $message);
    }

    public function setStatus(string $ulid, string $status, ChangeTaskStatus $change): void
    {
        $this->authorize('tasks.update', $this->organization());
        $target = TaskStatus::tryFrom($status) ?? abort(422);
        $task = $change->handle($this->task($ulid), $target, auth()->user());

        if ($this->editingId === $ulid && ! $target->isOpen()) {
            $this->closeEditor();
        }

        if ($target === TaskStatus::Completed) {
            $this->dispatch('toast', type: 'success', message: 'Done: '.$task->title);
        }
    }

    public function closeEditor(): void
    {
        $this->editing = false;
        $this->focus = '';
    }

    private function task(string $ulid): Task
    {
        return Task::query()->forOrganization($this->organization())->where('ulid', $ulid)->firstOrFail();
    }

    private function customerByUlid(string $ulid): ?Customer
    {
        return Customer::query()->forOrganization($this->organization())->where('ulid', $ulid)->first();
    }

    /** The form's local date and time, in the business's timezone, as a UTC instant. */
    private function dueAt(): ?CarbonImmutable
    {
        if (($this->form['due_date'] ?? '') === '') {
            return null;
        }

        $time = $this->form['due_time'] !== '' ? $this->form['due_time'] : '17:00'; // a date alone means end of the working day

        return CarbonImmutable::parse($this->form['due_date'].' '.$time, $this->organization()->timezoneOrDefault())->utc();
    }

    /** @return array<string, string> */
    private function blankForm(): array
    {
        return [
            'title' => '', 'type' => TaskType::Todo->value, 'priority' => TaskPriority::Normal->value,
            'due_date' => '', 'due_time' => '', 'assigned_to_user_id' => '', 'description' => '', 'customer' => '',
        ];
    }

    /**
     * Active teammates who can see tasks: the people a task can be given to.
     *
     * @return Collection<int, User>
     */
    private function team(): Collection
    {
        $organization = $this->organization();

        return $organization->members()->wherePivot('status', 'active')->where('users.is_active', true)
            ->orderBy('users.name')->get(['users.id', 'users.name', 'users.role'])
            ->filter(fn (User $u) => $u->hasPermissionIn('tasks.view', $organization))
            ->values();
    }

    public function render(): View
    {
        $organization = $this->organization();
        $base = fn () => Task::query()->forOrganization($organization);

        $tasks = $base()
            ->with(['customer:id,ulid,first_name,last_name,company', 'assignee:id,name', 'call:id,call_id'])
            ->when($this->search !== '', function (Builder $q) {
                $term = '%'.addcslashes($this->search, '%_\\').'%';
                $q->where(fn (Builder $w) => $w->where('title', 'like', $term)->orWhere('description', 'like', $term));
            })
            ->tap(fn (Builder $q) => match ($this->view) {
                'mine' => $q->open()->where('assigned_to_user_id', auth()->id())->byUrgency(),
                'overdue' => $q->overdue()->byUrgency(),
                'done' => $q->whereIn('status', [TaskStatus::Completed->value, TaskStatus::Cancelled->value])->latest('updated_at')->latest('id'),
                default => $q->open()->byUrgency(),
            })
            ->paginate(25);

        $editingTask = $this->editingId ? $base()->with(['customer', 'call:id,call_id'])->where('ulid', $this->editingId)->first() : null;
        $newFor = ! $this->editingId && ($this->form['customer'] ?? '') !== '' ? $this->customerByUlid($this->form['customer']) : null;

        return view('livewire.client.tasks.index', [
            'tasks' => $tasks,
            'views' => self::VIEWS,
            'counts' => [
                'open' => $base()->open()->count(),
                'mine' => $base()->open()->where('assigned_to_user_id', auth()->id())->count(),
                'overdue' => $base()->overdue()->count(),
            ],
            'types' => TaskType::cases(),
            'priorities' => TaskPriority::cases(),
            'team' => $this->editing ? $this->team() : collect(),
            'editingTask' => $editingTask,
            'newFor' => $newFor,
            'canCreate' => auth()->user()->can('tasks.create', $organization),
            'canUpdate' => auth()->user()->can('tasks.update', $organization),
            'timezone' => $organization->timezoneOrDefault(),
            'now' => now(),
        ]);
    }
}

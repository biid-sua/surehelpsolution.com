<?php

namespace App\Livewire\Agent\Training;

use App\Livewire\Concerns\AgentWorkspaceOnly;
use App\Models\Organization;
use App\Models\TrainingAssignment;
use App\Models\TrainingCategory;
use App\Models\TrainingCourse;
use App\Services\Training\TrainingAccess;
use App\Support\Audit\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Training management (spec §20B, brief §6, D41): the courses a person may manage, with how many
 * agents have them, and creating new ones. Supervisors see their companies' courses only.
 */
#[Layout('layouts.portal', ['portal' => 'agent'])]
#[Title('Training management')]
class Courses extends Component
{
    use AgentWorkspaceOnly, WithPagination;

    #[Url(except: '')]
    public string $search = '';

    /** '' = all, 'platform', or a company ulid */
    #[Url(except: '')]
    public string $for = '';

    #[Url(except: '')]
    public string $state = '';

    public bool $creating = false;

    /** @var array{title: string, for: string, category: string, new_category: string, summary: string} */
    public array $form = ['title' => '', 'for' => '', 'category' => '', 'new_category' => '', 'summary' => ''];

    public function mount(TrainingAccess $access): void
    {
        abort_unless($access->canOpenManagement(auth()->user()), 403);
    }

    public function updating(string $name): void
    {
        if (in_array($name, ['search', 'for', 'state'], true)) {
            $this->resetPage();
        }
    }

    /** @return array<string, string> where new courses may be created: '' = platform-wide, else company ulid */
    private function creatableFor(TrainingAccess $access): array
    {
        $user = auth()->user();
        $options = $user->hasPlatformPermission('training.create') ? ['platform' => 'All agents (platform-wide)'] : [];

        return $options + $access->companies($user, 'training.create')->orderBy('name')->pluck('name', 'ulid')->all();
    }

    public function startCreate(TrainingAccess $access): void
    {
        $choices = $this->creatableFor($access);
        abort_if($choices === [], 403);
        $this->form = ['title' => '', 'for' => (string) array_key_first($choices), 'category' => '', 'new_category' => '', 'summary' => ''];
        $this->creating = true;
    }

    public function create(TrainingAccess $access, Audit $audit): void
    {
        $this->validate([
            'form.title' => ['required', 'string', 'max:200'],
            'form.for' => ['required', 'string'],
            'form.summary' => ['nullable', 'string', 'max:500'],
            'form.category' => ['nullable', 'integer', 'exists:training_categories,id'],
            'form.new_category' => ['nullable', 'string', 'max:100'],
        ], [], ['form.title' => 'title', 'form.for' => 'audience']);

        $user = auth()->user();
        $organization = $this->form['for'] === 'platform' ? null : Organization::query()->where('ulid', $this->form['for'])->first();
        if (($this->form['for'] !== 'platform' && ! $organization) || ! $access->canCreateFor($user, $organization)) {
            $this->addError('form.for', 'You can\'t create training for that audience.');

            return;
        }

        $category = trim($this->form['new_category']) !== ''
            ? TrainingCategory::query()->firstOrCreate(['name' => trim($this->form['new_category'])])
            : ($this->form['category'] !== '' ? TrainingCategory::find((int) $this->form['category']) : null);

        $course = TrainingCourse::create([
            'organization_id' => $organization?->id,
            'category_id' => $category?->id,
            'title' => trim($this->form['title']),
            'summary' => trim($this->form['summary']) ?: null,
            'owner_user_id' => $user->id,
            'created_by_user_id' => $user->id,
            'updated_by_user_id' => $user->id,
        ]);
        $audit->record('training.course_created', $course, [], ['title' => $course->title, 'for' => $organization->name ?? 'All agents'], $organization, $user, $course->title);

        $this->redirectRoute('agent.training.course', $course->ulid);
    }

    public function render(TrainingAccess $access): View
    {
        $user = auth()->user();
        $term = trim($this->search);
        $courses = TrainingCourse::query()
            ->where(function (Builder $q) use ($access, $user) {
                $access->manageable($q, $user);
                // Supervisors give published platform-wide courses to their own agents (D41).
                if (! $user->hasPlatformPermission('training.update') && $user->hasPermissionIn('training.assign')) {
                    $q->orWhere(fn (Builder $q) => $q->whereNull('organization_id')->where('current_version', '>', 0)->where('is_active', true));
                }
            })
            ->when($term !== '', fn (Builder $q) => $q->where('title', 'like', '%'.addcslashes($term, '%_\\').'%'))
            ->when($this->for === 'platform', fn (Builder $q) => $q->whereNull('organization_id'))
            ->when($this->for !== '' && $this->for !== 'platform', fn (Builder $q) => $q->whereHas('organization', fn (Builder $q) => $q->where('ulid', $this->for)))
            ->when($this->state === 'draft', fn (Builder $q) => $q->where('current_version', 0))
            ->when($this->state === 'published', fn (Builder $q) => $q->where('current_version', '>', 0)->where('is_active', true))
            ->when($this->state === 'inactive', fn (Builder $q) => $q->where('is_active', false))
            ->with('organization:id,name', 'category:id,name')
            ->orderByDesc('updated_at')->paginate(20);

        $ids = $courses->getCollection()->pluck('id');
        $counts = TrainingAssignment::query()->whereIn('course_id', $ids)->open()
            ->selectRaw('course_id, COUNT(*) as total, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as done, SUM(CASE WHEN status != ? AND due_at < ? THEN 1 ELSE 0 END) as overdue',
                [TrainingAssignment::COMPLETED, TrainingAssignment::COMPLETED, now()])
            ->groupBy('course_id')->get()->keyBy('course_id');

        return view('livewire.agent.training.courses', [
            'courses' => $courses,
            'counts' => $counts,
            'companies' => $access->companies($user)->orderBy('name')->get(['organizations.ulid', 'organizations.name']),
            'canPlatform' => $user->hasPlatformPermission('training.update') || $user->hasPermissionIn('training.assign'),
            'canCreate' => $this->creatableFor($access) !== [],
            'choices' => $this->creating ? $this->creatableFor($access) : [],
            'categories' => TrainingCategory::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
        ]);
    }
}

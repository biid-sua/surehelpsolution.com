<?php

namespace App\Livewire\Agent\Training;

use App\Livewire\Concerns\AgentWorkspaceOnly;
use App\Models\Organization;
use App\Models\TrainingCourse;
use App\Models\TrainingPath;
use App\Services\Training\TrainingAccess;
use App\Support\Audit\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Learning paths (brief §1.4): courses in a recommended order, e.g. "Customer service" →
 * fundamentals, call handling, escalation. Platform-wide or for one company; a company path can
 * include platform courses and that company's courses only.
 */
#[Layout('layouts.portal', ['portal' => 'agent'])]
#[Title('Learning paths')]
class Paths extends Component
{
    use AgentWorkspaceOnly;

    /** null = not editing, 0 = a new path */
    #[Locked]
    public ?int $editing = null;

    /** @var array{title: string, description: string, for: string, is_active: bool, courses: list<int>} */
    public array $form = ['title' => '', 'description' => '', 'for' => '', 'is_active' => true, 'courses' => []];

    public string $addCourse = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionIn('training.update'), 403);
    }

    /** @return array<string, string> */
    private function audiences(TrainingAccess $access): array
    {
        $user = auth()->user();

        return ($user->hasPlatformPermission('training.update') ? ['platform' => 'All agents (platform-wide)'] : [])
            + $access->companies($user, 'training.update')->orderBy('name')->pluck('name', 'ulid')->all();
    }

    private function organizationFor(string $for): ?Organization
    {
        return $for === 'platform' ? null : Organization::query()->where('ulid', $for)->first();
    }

    /** @return Builder<TrainingCourse> courses a path for this audience may contain */
    private function eligibleCourses(string $for): Builder
    {
        $organization = $this->organizationFor($for);

        return TrainingCourse::query()->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('organization_id')->when($organization, fn (Builder $q) => $q->orWhere('organization_id', $organization->id)));
    }

    private function path(int $id, TrainingAccess $access): TrainingPath
    {
        $path = TrainingPath::query()->findOrFail($id);
        abort_unless($access->canManage(auth()->user(), $path), 404);

        return $path;
    }

    public function create(TrainingAccess $access): void
    {
        $audiences = $this->audiences($access);
        abort_if($audiences === [], 403);
        $this->editing = 0;
        $this->form = ['title' => '', 'description' => '', 'for' => (string) array_key_first($audiences), 'is_active' => true, 'courses' => []];
    }

    public function edit(int $id, TrainingAccess $access): void
    {
        $path = $this->path($id, $access);
        $this->editing = $path->id;
        $this->form = [
            'title' => $path->title, 'description' => (string) $path->description,
            'for' => $path->organization->ulid ?? 'platform', 'is_active' => $path->is_active,
            'courses' => $path->courses->pluck('id')->map(fn ($id) => (int) $id)->all(),
        ];
    }

    public function cancel(): void
    {
        $this->editing = null;
    }

    public function add(): void
    {
        $id = (int) $this->addCourse;
        if ($id && ! in_array($id, $this->form['courses'], true) && $this->eligibleCourses($this->form['for'])->whereKey($id)->exists()) {
            $this->form['courses'][] = $id;
        }
        $this->addCourse = '';
    }

    public function move(int $index, int $direction): void
    {
        $to = $index + ($direction < 0 ? -1 : 1);
        if (isset($this->form['courses'][$index], $this->form['courses'][$to])) {
            [$this->form['courses'][$index], $this->form['courses'][$to]] = [$this->form['courses'][$to], $this->form['courses'][$index]];
        }
    }

    public function remove(int $index): void
    {
        unset($this->form['courses'][$index]);
        $this->form['courses'] = array_values($this->form['courses']);
    }

    public function save(TrainingAccess $access, Audit $audit): void
    {
        $this->form['courses'] = array_map('intval', $this->form['courses']);
        $this->validate([
            'form.title' => ['required', 'string', 'max:200'],
            'form.description' => ['nullable', 'string', 'max:2000'],
            'form.for' => ['required', 'string'],
            'form.courses' => ['required', 'array', 'min:1'],
        ], ['form.courses.required' => 'Add at least one course.'], ['form.title' => 'title']);

        $user = auth()->user();
        $organization = $this->organizationFor($this->form['for']);
        $path = $this->editing ? $this->path($this->editing, $access) : null;
        if (! $path && ! $access->canCreateFor($user, $organization, 'training.update')) {
            $this->addError('form.for', 'You can\'t create paths for that audience.');

            return;
        }
        $courses = $this->eligibleCourses($path ? ($path->organization->ulid ?? 'platform') : $this->form['for'])
            ->whereIn('id', $this->form['courses'])->pluck('id')->all();
        $ordered = array_values(array_filter($this->form['courses'], fn (int $id) => in_array($id, $courses, true)));

        $path ??= new TrainingPath(['organization_id' => $organization?->id, 'created_by_user_id' => $user->id]);
        $path->fill(['title' => trim($this->form['title']), 'description' => trim($this->form['description']) ?: null, 'is_active' => (bool) $this->form['is_active']])->save();
        $path->courses()->sync(collect($ordered)->mapWithKeys(fn (int $id, int $i) => [$id => ['position' => $i]])->all());
        $audit->record('training.path_saved', $path, [], ['title' => $path->title, 'courses' => count($ordered)], $path->organization, $user, $path->title);

        $this->editing = null;
        $this->dispatch('toast', type: 'success', message: 'Learning path saved.');
    }

    public function render(TrainingAccess $access): View
    {
        $user = auth()->user();
        $platform = $user->hasPlatformPermission('training.update');
        $companies = $access->companies($user, 'training.update')->select('organizations.id');

        return view('livewire.agent.training.paths', [
            'paths' => TrainingPath::query()
                ->where(fn (Builder $q) => $q->whereIn('organization_id', $companies)->when($platform, fn (Builder $q) => $q->orWhereNull('organization_id')))
                ->with('organization:id,name', 'courses:training_courses.id,title')->orderBy('title')->get(),
            'audiences' => $this->editing !== null ? $this->audiences($access) : [],
            'eligible' => $this->editing !== null ? $this->eligibleCourses($this->form['for'])->orderBy('title')->pluck('title', 'id')->all() : [],
        ]);
    }
}

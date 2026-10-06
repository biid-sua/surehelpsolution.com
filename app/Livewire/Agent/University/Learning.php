<?php

namespace App\Livewire\Agent\University;

use App\Livewire\Concerns\AgentWorkspaceOnly;
use App\Models\TrainingAssignment;
use App\Models\TrainingCategory;
use App\Models\TrainingCertificate;
use App\Models\TrainingCompletion;
use App\Models\TrainingCourse;
use App\Models\TrainingPath;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Agent University for the learner (spec §20B, brief §1.1–1.2): what's required, in progress,
 * overdue and done, certificates, and courses worth taking. Company courses appear only for
 * companies the learner serves now (D40).
 */
#[Layout('layouts.portal', ['portal' => 'agent'])]
#[Title('Agent University')]
class Learning extends Component
{
    use AgentWorkspaceOnly, WithPagination;

    public const TABS = [
        'overview' => 'My learning',
        'assigned' => 'Assigned training',
        'recommended' => 'Recommended',
        'completed' => 'Completed',
        'certificates' => 'Certifications',
        'history' => 'Training history',
    ];

    #[Url(except: 'overview')]
    public string $tab = 'overview';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $category = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->hasPermissionIn('agent_university.view'), 403);
        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'overview';
        }
    }

    public function updating(string $name): void
    {
        if (in_array($name, ['tab', 'search', 'category'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $user = auth()->user();
        $mine = TrainingAssignment::query()->where('agent_user_id', $user->id)->open()->visibleTo($user)
            ->with(['course:id,ulid,title,summary,organization_id,estimated_minutes,difficulty,category_id,thumbnail_path,current_version,valid_for_months', 'course.organization:id,name', 'course.category:id,name'])
            ->get();

        $required = $mine->where('is_required', true);
        $data = [
            'tabs' => self::TABS,
            'stats' => [
                'progress' => $mine->isEmpty() ? null : (int) round($mine->avg(fn (TrainingAssignment $a) => $a->isDone() ? 100 : $a->progress_percent)),
                'required' => $required->count(),
                'required_done' => $required->filter->isDone()->count(),
                'overdue' => $mine->filter(fn (TrainingAssignment $a) => $a->effectiveStatus() === 'overdue')->count(),
                'certificates' => TrainingCertificate::query()->where('agent_user_id', $user->id)->where('status', TrainingCertificate::ACTIVE)
                    ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count(),
            ],
        ];

        $data += match ($this->tab) {
            'assigned' => ['rows' => $this->sorted($mine->reject->isDone())],
            'completed' => ['rows' => $mine->filter->isDone()->sortByDesc('completed_at')->values()],
            'recommended' => $this->catalog($mine),
            'certificates' => ['certificates' => TrainingCertificate::query()->where('agent_user_id', $user->id)->with('course:id,ulid,title')->latest('issued_at')->get()],
            'history' => ['history' => TrainingCompletion::query()->where('agent_user_id', $user->id)
                ->whereHas('course', fn (Builder $q) => $q->availableTo($user))
                ->with('course:id,ulid,title,organization_id', 'course.organization:id,name')->latest('completed_at')->paginate(25)],
            default => $this->overview($mine),
        };

        return view('livewire.agent.university.learning', $data);
    }

    /**
     * Required first, then overdue, then by due date.
     *
     * @param  Collection<int, TrainingAssignment>  $rows
     * @return Collection<int, TrainingAssignment>
     */
    private function sorted($rows)
    {
        return $rows->sortBy([
            fn (TrainingAssignment $a, TrainingAssignment $b) => $b->is_required <=> $a->is_required,
            fn (TrainingAssignment $a, TrainingAssignment $b) => ($a->effectiveStatus() === 'overdue' ? 0 : 1) <=> ($b->effectiveStatus() === 'overdue' ? 0 : 1),
            fn (TrainingAssignment $a, TrainingAssignment $b) => ($a->due_at->timestamp ?? PHP_INT_MAX) <=> ($b->due_at->timestamp ?? PHP_INT_MAX),
        ])->values();
    }

    /**
     * @param  Collection<int, TrainingAssignment>  $mine
     * @return array<string, mixed>
     */
    private function overview($mine): array
    {
        $user = auth()->user();
        $taken = $mine->pluck('course_id');

        return [
            'todo' => $this->sorted($mine->reject->isDone())->take(6),
            'recent' => TrainingCompletion::query()->where('agent_user_id', $user->id)->whereHas('course', fn (Builder $q) => $q->availableTo($user))
                ->with('course:id,ulid,title')->latest('completed_at')->limit(4)->get(),
            'expiring' => TrainingCertificate::query()->where('agent_user_id', $user->id)->where('status', TrainingCertificate::ACTIVE)
                ->whereNotNull('expires_at')->where('expires_at', '<=', now()->addDays(TrainingCertificate::EXPIRING_DAYS))
                ->with('course:id,ulid,title')->orderBy('expires_at')->get(),
            'suggested' => TrainingCourse::query()->availableTo($user)->whereNotIn('id', $taken)->with('organization:id,name')
                ->orderByDesc('published_at')->limit(3)->get(),
            'paths' => TrainingPath::query()->availableTo($user)->with(['courses' => fn ($q) => $q->availableTo($user)->select('training_courses.id', 'ulid', 'title')])
                ->orderBy('title')->get()->filter(fn (TrainingPath $p) => $p->courses->isNotEmpty())->values(),
            'byCourse' => $mine->keyBy('course_id'),
        ];
    }

    /**
     * Courses open to the learner that they haven't got yet.
     *
     * @param  Collection<int, TrainingAssignment>  $mine
     * @return array<string, mixed>
     */
    private function catalog($mine): array
    {
        $user = auth()->user();
        $term = trim($this->search);

        return [
            'courses' => TrainingCourse::query()->availableTo($user)->whereNotIn('id', $mine->pluck('course_id'))
                ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q->where('title', 'like', '%'.addcslashes($term, '%_\\').'%')
                    ->orWhere('summary', 'like', '%'.addcslashes($term, '%_\\').'%')))
                ->when($this->category !== '', fn (Builder $q) => $q->where('category_id', (int) $this->category))
                ->with('organization:id,name', 'category:id,name')
                // Courses for the learner's own companies first: they matter most to their work.
                ->orderByRaw('CASE WHEN organization_id IS NULL THEN 1 ELSE 0 END')->orderBy('title')->paginate(12),
            'categories' => TrainingCategory::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
        ];
    }
}

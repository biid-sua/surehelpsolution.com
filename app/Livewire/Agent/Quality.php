<?php

namespace App\Livewire\Agent;

use App\Livewire\Concerns\AgentWorkspaceOnly;
use App\Models\CallLog;
use App\Models\QaReview;
use App\Models\User;
use App\Services\Quality\QualityReviews;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Call quality (spec SUP-04, AGT-12): agents read the feedback on their scored calls; supervisors
 * and SureHelp managers work the review queue (daily random sample plus calls they pick) and see
 * how each agent is doing.
 */
class Quality extends Component
{
    use AgentWorkspaceOnly;
    use WithPagination;

    public const TABS = ['queue' => 'To review', 'team' => 'Team results', 'mine' => 'My feedback'];

    #[Url(except: '')]
    public string $tab = '';

    /** The review open in the side panel (ULID). */
    #[Url(except: '')]
    public string $review = '';

    #[Url(except: '')]
    public string $agent = '';

    public string $callId = '';

    /** @var array<string, string> criterion => met|partly|missed|na */
    public array $marks = [];

    public string $strengths = '';

    public string $improvements = '';

    public function mount(): void
    {
        if ($this->review !== '') {
            $this->open($this->review);
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['tab', 'agent'], true)) {
            $this->resetPage();
        }
    }

    public function open(string $ulid): void
    {
        $review = $this->find($ulid);
        $this->review = $review->ulid;
        $this->resetValidation();
        $this->marks = collect($review->scores ?? [])->map(fn (array $c) => $c['mark'])->all();
        $this->strengths = (string) $review->strengths;
        $this->improvements = (string) $review->improvements;
    }

    public function close(): void
    {
        $this->reset('review', 'marks', 'strengths', 'improvements');
        $this->resetValidation();
    }

    /**
     * Supervisors: start a review of a call by its ID.
     */
    public function pick(QualityReviews $reviews): void
    {
        $user = $this->user();
        abort_unless($reviews->canReviewAny($user), 403);
        $this->validate(['callId' => ['required', 'string', 'max:40']], attributes: ['callId' => 'call ID']);

        $call = $reviews->reviewableCalls($user)->where('call_id', strtoupper(trim($this->callId)))->first();
        if (! $call) {
            $this->addError('callId', 'No call with that ID that you can review. You can\'t review your own calls.');

            return;
        }

        $this->reset('callId');
        $this->open($reviews->start($call, $user)->ulid);
    }

    /**
     * Supervisors: a random call from the last week that nobody has reviewed yet.
     */
    public function random(QualityReviews $reviews): void
    {
        $user = $this->user();
        abort_unless($reviews->canReviewAny($user), 403);

        $call = $reviews->reviewableCalls($user)->whereNotNull('organization_id')
            ->where('created_at', '>=', now()->subDays(7))->whereDoesntHave('qaReview')->inRandomOrder()->first();

        if (! $call) {
            $this->dispatch('toast', type: 'info', message: 'Every call from the last week has been reviewed.');

            return;
        }

        $this->open($reviews->start($call, $user)->ulid);
    }

    public function save(QualityReviews $reviews): void
    {
        $user = $this->user();
        $review = $reviews->reviewable($user)->where('ulid', $this->review)->firstOrFail();

        $this->validate([
            'strengths' => ['nullable', 'string', 'max:2000'],
            'improvements' => ['nullable', 'string', 'max:2000'],
        ]);

        $wasCompleted = $review->isCompleted();
        $reviews->complete($review, $user, $this->marks, $this->strengths, $this->improvements);

        $this->dispatch('toast', type: 'success', message: $wasCompleted ? 'Review updated. The agent has been told.' : 'Review saved. The agent can read it now.');
        $this->close();
    }

    /**
     * Agents: confirm they've read the feedback.
     */
    public function acknowledge(): void
    {
        $review = QaReview::query()->completed()->where('agent_user_id', $this->user()->id)->where('ulid', $this->review)->firstOrFail();
        $review->forceFill(['acknowledged_at' => now()])->save();

        $this->dispatch('toast', type: 'success', message: 'Thanks. Your reviewer can see you\'ve read it.');
        $this->close();
    }

    /**
     * A review the user may open: their own scored calls, or ones they may review.
     */
    private function find(string $ulid): QaReview
    {
        $user = $this->user();
        $own = QaReview::query()->completed()->where('agent_user_id', $user->id)->where('ulid', $ulid)->first();

        return $own ?? app(QualityReviews::class)->reviewable($user)->where('ulid', $ulid)->firstOrFail();
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }

    public function render(QualityReviews $reviews): View
    {
        $user = $this->user();
        $canReview = $reviews->canReviewAny($user);
        $tabs = $canReview ? self::TABS : ['mine' => self::TABS['mine']];
        $tab = array_key_exists($this->tab, $tabs) ? $this->tab : array_key_first($tabs);
        $since = now()->subDays(30);

        $data = [
            'tabs' => $tabs,
            'current' => $tab,
            'canReview' => $canReview,
            'scorecard' => $reviews->scorecard(),
            'markLabels' => QaReview::MARKS,
            'passScore' => (int) config('quality.pass_score'),
            'open' => $this->review !== '' ? $this->find($this->review)->load(['call', 'organization:id,ulid,name,timezone', 'agent:id,name', 'reviewer:id,name']) : null,
            'pendingCount' => $canReview ? $reviews->reviewable($user)->pending()->count() : 0,
        ];

        $data['canEdit'] = $data['open'] && $canReview && $data['open']->agent_user_id !== $user->id;

        if ($tab === 'mine') {
            $data['summary'] = $reviews->summaryFor($user, $since);
            $data['reviews'] = QaReview::query()->completed()->where('agent_user_id', $user->id)
                ->with(['call:id,call_id,caller_name,created_at', 'organization:id,name'])->latest('reviewed_at')->paginate(15);
        } elseif ($tab === 'queue') {
            $data['reviews'] = $reviews->reviewable($user)->pending()
                ->with(['call:id,call_id,caller_name,reason_for_call,created_at', 'organization:id,name', 'agent:id,name'])
                ->oldest()->paginate(15);
        } else {
            $completed = fn () => $reviews->reviewable($user)->completed()->where('reviewed_at', '>=', $since);
            $data['agents'] = $completed()->whereNotNull('agent_user_id')
                ->selectRaw('agent_user_id, COUNT(*) as reviews, AVG(score) as average, SUM(CASE WHEN passed THEN 1 ELSE 0 END) as passes')
                ->groupBy('agent_user_id')->with('agent:id,name')->get()
                ->sortBy(fn (QaReview $r) => (float) $r->getAttribute('average'))->values();
            $data['reviews'] = $reviews->reviewable($user)->completed()
                ->when($this->agent !== '', fn (Builder $q) => $q->where('agent_user_id', (int) $this->agent))
                ->with(['call:id,call_id,caller_name', 'organization:id,name', 'agent:id,name', 'reviewer:id,name'])
                ->latest('reviewed_at')->paginate(15);
        }

        return view('livewire.agent.quality', $data)
            ->layout('layouts.portal', ['portal' => $user->isAdmin() ? 'admin' : 'agent'])
            ->title('Call quality');
    }

    /**
     * Shown next to the call in the review panel.
     *
     * @return array<string, string|null>
     */
    public static function callFacts(CallLog $call): array
    {
        return [
            'Caller' => CallLog::display($call->caller_name).($call->caller_phone ? ' · '.$call->caller_phone : ''),
            'Reason' => CallLog::REASONS[$call->reason_for_call] ?? str((string) $call->reason_for_call)->headline()->toString(),
            'Outcome' => $call->statusLabel(),
            'Agent' => $call->agent_name,
            'Service' => $call->service_request ? trim(($call->service_date?->toFormattedDateString() ?? '').' '.($call->service_window ?? '').' '.($call->service_location ? '· '.$call->service_location : '')) ?: 'Requested' : null,
        ];
    }
}

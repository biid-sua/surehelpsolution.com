<?php

namespace App\Livewire\Client\Social;

use App\Enums\SocialNetwork;
use App\Enums\SocialPostStatus;
use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The content calendar (spec §41): a month view in the business's timezone, a list with filters,
 * and the posts waiting for approval.
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Social media')]
class Index extends Component
{
    use ScopedToOrganization, WithPagination;

    #[Url(except: 'calendar')]
    public string $view = 'calendar';

    #[Url(except: '')]
    public string $month = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $network = '';

    public function mount(): void
    {
        $this->authorize('social.view', $this->organization());
        if (! in_array($this->view, ['calendar', 'list', 'approval'], true)) {
            $this->view = 'calendar';
        }

        if ($message = session('success')) {
            $this->dispatch('toast', type: 'success', message: $message);
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['view', 'status', 'network'], true)) {
            $this->resetPage();
        }
    }

    public function shiftMonth(int $by): void
    {
        $this->month = $this->monthStart()->addMonthsNoOverflow($by)->format('Y-m');
    }

    private function monthStart(): CarbonImmutable
    {
        $timezone = $this->organization()->timezoneOrDefault();

        return preg_match('/^\d{4}-\d{2}$/', $this->month)
            ? CarbonImmutable::createFromFormat('Y-m-d', $this->month.'-01', $timezone)->startOfDay()
            : CarbonImmutable::now($timezone)->startOfMonth();
    }

    /**
     * @return Builder<SocialPost>
     */
    private function filtered(): Builder
    {
        return SocialPost::query()->forOrganization($this->organization())
            ->with(['targets.account'])
            ->when(SocialPostStatus::tryFrom($this->status), fn (Builder $q, SocialPostStatus $s) => $q->where('status', $s))
            ->when(SocialNetwork::tryFrom($this->network), fn (Builder $q, SocialNetwork $n) => $q->whereHas('targets.account', fn (Builder $a) => $a->where('network', $n)));
    }

    public function render(): View
    {
        $organization = $this->organization();
        $timezone = $organization->timezoneOrDefault();
        $data = [
            'timezone' => $timezone,
            'statuses' => SocialPostStatus::cases(),
            'networks' => SocialNetwork::cases(),
            'awaiting' => SocialPost::query()->forOrganization($organization)->where('status', SocialPostStatus::InReview)->count(),
            'hasAccounts' => SocialAccount::query()->forOrganization($organization)->where('is_enabled', true)->exists(),
            'canManage' => auth()->user()->can('social.manage', $organization),
        ];

        if ($this->view === 'calendar') {
            $start = $this->monthStart();
            $gridStart = $start->startOfWeek(CarbonImmutable::MONDAY);
            $gridEnd = $start->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY);

            $posts = $this->filtered()
                ->where('status', '!=', SocialPostStatus::Cancelled)
                ->where(fn (Builder $q) => $q
                    ->whereBetween('scheduled_at', [$gridStart->utc(), $gridEnd->utc()])
                    ->orWhere(fn (Builder $p) => $p->whereNull('scheduled_at')->whereBetween('published_at', [$gridStart->utc(), $gridEnd->utc()])))
                ->get()
                ->groupBy(fn (SocialPost $p) => ($p->scheduled_at ?? $p->published_at ?? $p->created_at)->setTimezone($timezone)->format('Y-m-d'));

            $weeks = [];
            for ($day = $gridStart, $i = 0; $day->lessThanOrEqualTo($gridEnd); $day = $day->addDay(), $i++) {
                $weeks[intdiv($i, 7)][] = $day;
            }

            return view('livewire.client.social.index', $data + [
                'monthStart' => $start,
                'weeks' => $weeks,
                'byDay' => $posts,
                'today' => CarbonImmutable::now($timezone)->format('Y-m-d'),
            ]);
        }

        $query = $this->view === 'approval'
            ? $this->filtered()->where('status', SocialPostStatus::InReview)->orderBy('scheduled_at')
            : $this->filtered()->orderByRaw('scheduled_at IS NULL')->orderByDesc('scheduled_at')->orderByDesc('id');

        return view('livewire.client.social.index', $data + ['posts' => $query->paginate(20)]);
    }
}

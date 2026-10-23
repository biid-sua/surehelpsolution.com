<?php

namespace App\Livewire\Client;

use App\Enums\SocialNetwork;
use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\Review;
use App\Models\SocialAccount;
use App\Services\Reviews\ReviewDesk;
use App\Services\Social\SocialManager;
use App\Support\Audit\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Reviews (D83): the business's Google reviews, the rating over time, and replies, drafted with AI
 * when asked and posted to Google from here.
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Reviews')]
class Reviews extends Component
{
    use ScopedToOrganization, WithPagination;

    public const FILTERS = ['waiting' => 'Waiting for a reply', 'all' => 'All', 'low' => '1–3 stars'];

    #[Url]
    public string $filter = 'waiting';

    #[Url(as: 'review')]
    public string $open = '';

    public string $text = '';

    public function mount(): void
    {
        $this->authorize('reviews.view', $this->organization());
        if (! array_key_exists($this->filter, self::FILTERS)) {
            $this->filter = 'waiting';
        }
        if ($this->open !== '') {
            $review = $this->find($this->open);
            $this->filter = $review->status === Review::NEW ? 'waiting' : 'all';
            $this->text = (string) ($review->draft ?? $review->reply);
        }
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function compose(string $ulid): void
    {
        $this->authorize('reviews.manage', $this->organization());
        $review = $this->find($ulid);
        $this->open = $review->ulid;
        $this->text = (string) ($review->draft ?? $review->reply);
        $this->resetValidation();
    }

    public function close(): void
    {
        $this->open = '';
        $this->text = '';
        $this->resetValidation();
    }

    public function draft(ReviewDesk $desk): void
    {
        $this->authorize('reviews.manage', $this->organization());
        $review = $this->find($this->open);
        if (RateLimiter::tooManyAttempts('review-draft:'.auth()->id(), 20)) {
            $this->addError('draft', 'That\'s a lot of drafts in a row. Wait a few minutes.');

            return;
        }
        RateLimiter::hit('review-draft:'.auth()->id(), 600);
        try {
            $this->text = $desk->draft($review, auth()->user());
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());
        }
    }

    public function post(ReviewDesk $desk): void
    {
        $this->authorize('reviews.manage', $this->organization());
        $this->validate(['text' => ['required', 'string', 'min:2', 'max:4000']], [], ['text' => 'reply']);
        try {
            $desk->reply($this->find($this->open), $this->text, auth()->user());
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }
        $this->close();
        $this->dispatch('toast', type: 'success', message: 'Reply posted on Google.');
    }

    public function dismiss(string $ulid, Audit $audit): void
    {
        $this->authorize('reviews.manage', $this->organization());
        $review = $this->find($ulid);
        $review->forceFill(['status' => $review->status === Review::DISMISSED ? Review::NEW : Review::DISMISSED])->save();
        $audit->changes('review.updated', $review, ['status']);
        if ($this->open === $ulid) {
            $this->close();
        }
    }

    public function refresh(ReviewDesk $desk): void
    {
        $organization = $this->organization();
        $this->authorize('reviews.view', $organization);
        $key = 'reviews-sync:'.$organization->id;
        if (RateLimiter::tooManyAttempts($key, 1)) {
            $this->dispatch('toast', type: 'info', message: 'Checked a moment ago. Reviews are also checked every hour.');

            return;
        }
        RateLimiter::hit($key, 120);
        $counts = $desk->sync($organization);
        $this->dispatch('toast', type: 'success', message: $counts['new'] ? $counts['new'].' new '.str('review')->plural($counts['new']).'.' : 'No new reviews.');
    }

    private function find(string $ulid): Review
    {
        return Review::query()->forOrganization($this->organization())->where('ulid', $ulid)->firstOrFail();
    }

    public function render(ReviewDesk $desk, SocialManager $social): View
    {
        $organization = $this->organization();
        $query = Review::query()->forOrganization($organization)->with('repliedBy:id,name')
            ->when($this->filter === 'waiting', fn ($q) => $q->where('status', Review::NEW))
            ->when($this->filter === 'low', fn ($q) => $q->where('rating', '<=', 3))
            ->orderByDesc('reviewed_at');

        $accounts = $desk->accounts($organization);
        $google = $social->connectors()['google'] ?? null;

        return view('livewire.client.reviews', [
            'reviews' => $query->paginate(15),
            'summary' => $desk->summary($organization),
            'accounts' => $accounts,
            'reconnect' => SocialAccount::query()->forOrganization($organization)->where('network', SocialNetwork::GoogleBusiness->value)
                ->where('status', SocialAccount::STATUS_NEEDS_REAUTH)->exists(),
            'canConnect' => $google !== null && $google->isConfigured() && auth()->user()->can('social.manage', $organization),
            'canManage' => auth()->user()->can('reviews.manage', $organization),
            'draftBlocker' => $desk->draftBlocker($organization),
            'filters' => self::FILTERS,
            'timezone' => $organization->timezoneOrDefault(),
        ]);
    }
}

<?php

namespace App\Services\Reviews;

use App\Actions\Notifications\NotifyOrganization;
use App\Actions\Tasks\CreateTask;
use App\Enums\SocialNetwork;
use App\Enums\TaskType;
use App\Exceptions\AiUnavailable;
use App\Exceptions\SocialAuthorizationLost;
use App\Models\AiRun;
use App\Models\BusinessProfile;
use App\Models\Organization;
use App\Models\Review;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\ReviewReceived;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Data\AiRequest;
use App\Services\Automation\AutomationEngine;
use App\Services\Social\SocialManager;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Reviews and reputation (D83): keeps a business's Google reviews in step, tells the team about new
 * ones (with a task for low ratings), drafts replies with AI when asked, and posts replies to Google.
 */
class ReviewDesk
{
    public function __construct(
        private readonly GoogleReviews $google,
        private readonly SocialManager $social,
        private readonly NotifyOrganization $notify,
        private readonly CreateTask $createTask,
        private readonly AiProvider $ai,
        private readonly Audit $audit,
    ) {}

    /** @return Collection<int, SocialAccount> the business's connected Google locations */
    public function accounts(Organization $organization): Collection
    {
        return SocialAccount::withoutGlobalScopes()->where('organization_id', $organization->id)
            ->where('network', SocialNetwork::GoogleBusiness->value)->where('status', SocialAccount::STATUS_ACTIVE)->get();
    }

    /**
     * Read the latest reviews of each location. The first time, the history is imported quietly.
     *
     * @return array{new: int, updated: int}
     */
    public function sync(Organization $organization): array
    {
        $counts = ['new' => 0, 'updated' => 0];
        foreach ($this->accounts($organization) as $account) {
            $first = empty($account->meta['reviews_synced_at']);
            try {
                $data = $this->google->fetch($account, $this->social->accessToken($account), $first ? (int) config('reviews.first_import', 200) : 50);
            } catch (SocialAuthorizationLost $e) {
                $this->social->lost($account, $e->getMessage());

                continue;
            }

            foreach ($data['reviews'] as $r) {
                $review = Review::withoutGlobalScopes()->where('organization_id', $organization->id)->where('source', 'google')->where('external_id', $r['id'])->first();
                if ($review) {
                    $changes = ['rating' => $r['rating'], 'comment' => $r['comment'], 'author_name' => $r['author']];
                    if ($r['reply'] && $review->reply !== $r['reply']) {
                        // Answered on Google directly, or our own reply coming back.
                        $changes += ['reply' => $r['reply'], 'replied_at' => $r['replied_at'] ?? now(), 'status' => Review::REPLIED];
                    }
                    $review->fill($changes);
                    if ($review->isDirty()) {
                        $review->save();
                        $counts['updated']++;
                    }

                    continue;
                }

                $review = Review::create([
                    'organization_id' => $organization->id, 'social_account_id' => $account->id, 'source' => 'google', 'external_id' => $r['id'],
                    'author_name' => $r['author'], 'rating' => $r['rating'], 'comment' => $r['comment'], 'reviewed_at' => $r['at'],
                    'reply' => $r['reply'], 'replied_at' => $r['reply'] ? ($r['replied_at'] ?? $r['at']) : null,
                    // Old unanswered reviews from the first import don't pile up as work.
                    'status' => $r['reply'] ? Review::REPLIED : ($first && $r['at']->lt(now()->subDays(30)) ? Review::DISMISSED : Review::NEW),
                ]);
                $counts['new']++;
                if (! $first) {
                    $this->announce($organization, $review);
                }
            }

            $account->forceFill(['meta' => array_merge($account->meta ?? [], [
                'reviews_synced_at' => now()->toIso8601String(),
                'rating' => $data['average'], 'review_count' => $data['total'],
            ])])->save();
        }

        return $counts;
    }

    private function announce(Organization $organization, Review $review): void
    {
        $this->notify->handle($organization, new ReviewReceived($review), 'reviews.view');
        if ($review->rating <= (int) config('reviews.low_rating', 2) && ! $review->reply) {
            $this->createTask->handle($organization, [
                'type' => TaskType::FollowUp,
                'title' => 'Reply to '.$review->authorLabel().'\'s '.$review->rating.'-star review',
                'description' => mb_substr((string) $review->comment, 0, 1000) ?: 'No comment, just the rating.',
                'priority' => 'high',
                'due_at' => now()->addDay(),
            ], null, 'review');
        }
        app(AutomationEngine::class)->fire('review_received', $review);
    }

    /** Why AI drafts can't be made now, or null. */
    public function draftBlocker(Organization $organization): ?string
    {
        $used = Review::withoutGlobalScopes()->where('organization_id', $organization->id)->where('drafted_at', '>=', now()->startOfMonth())->count();

        return match (true) {
            ! $this->ai->isConfigured() => 'AI drafts aren\'t switched on yet.',
            $used >= (int) config('reviews.ai_monthly_drafts', 100) => 'You\'ve used this month\'s AI drafts. They renew on the 1st.',
            default => null,
        };
    }

    /**
     * A suggested reply, saved on the review. Nothing is posted.
     *
     * @throws ValidationException
     */
    public function draft(Review $review, User $by): string
    {
        $organization = Organization::query()->findOrFail($review->organization_id);
        if ($blocker = $this->draftBlocker($organization)) {
            throw ValidationException::withMessages(['draft' => [$blocker]]);
        }
        $profile = BusinessProfile::withoutGlobalScopes()->where('organization_id', $organization->id)->first();
        $name = $profile?->display_name ?: $organization->name;
        $instructions = implode("\n", array_filter([
            "You write replies from the business \"{$name}\" to its Google reviews. The reply is public.",
            'Rules:',
            '- 2 to 4 short sentences, warm and professional, in the language of the review.',
            '- Thank the reviewer, using their first name if there is one.',
            '- 4 or 5 stars: thank them and mention something specific from their review if there is something.',
            '- 1 to 3 stars: apologise for their experience without arguing or admitting fault, and invite them to contact the business directly'.($profile?->phone ? ' at '.$profile->phone : '').'.',
            '- Never mention private details: no appointment details, health, prices, or anything not in the review itself.',
            '- No placeholders, no hashtags, no emojis. Sign off with the business name.',
            $profile?->brand_voice ? 'How the business sounds: '.mb_substr($profile->brand_voice, 0, 400) : null,
        ]));
        $prompt = "Reviewer: {$review->authorLabel()}\nStars: {$review->rating} of 5\nReview: ".($review->comment ?: '(no text, just the rating)');

        try {
            $response = $this->ai->complete(new AiRequest($instructions, '', [['role' => 'user', 'content' => $prompt]],
                outputSchema: ['type' => 'object', 'properties' => ['reply' => ['type' => 'string']], 'required' => ['reply'], 'additionalProperties' => false],
                effort: 'low', model: config('reviews.ai_model') ?: null, maxTokens: 600));
        } catch (AiUnavailable) {
            throw ValidationException::withMessages(['draft' => ['The AI service is unavailable right now. Try again in a few minutes.']]);
        }
        AiRun::create(['organization_id' => $organization->id, 'mode' => 'review', 'model' => $this->ai->model(), 'status' => 'drafted',
            'input_tokens' => $response->usage['input'] + $response->usage['cache_read'], 'output_tokens' => $response->usage['output']]);
        $reply = $response->stopReason === 'end_turn' ? trim((string) (json_decode($response->text, true)['reply'] ?? '')) : '';
        if ($reply === '') {
            throw ValidationException::withMessages(['draft' => ['The AI couldn\'t write a reply for this one. Write your own, or try again.']]);
        }
        $review->forceFill(['draft' => mb_substr($reply, 0, 4000), 'drafted_at' => now()])->save();

        return $reply;
    }

    /**
     * Post a reply on Google (replacing an earlier one).
     *
     * @throws ValidationException
     */
    public function reply(Review $review, string $text, User $by): void
    {
        $text = trim($text);
        $account = $review->account;
        if (! $account || $account->needsReconnect()) {
            throw ValidationException::withMessages(['reply' => ['Reconnect your Google Business Profile in Social › Accounts, then try again.']]);
        }
        try {
            $this->google->reply($account, $this->social->accessToken($account), $review->external_id, $text);
        } catch (SocialAuthorizationLost $e) {
            $this->social->lost($account, $e->getMessage());
            throw ValidationException::withMessages(['reply' => ['Google no longer lets us reply for you. Reconnect your Business Profile in Social › Accounts.']]);
        } catch (\Throwable $e) {
            report($e);
            $review->forceFill(['error' => mb_substr($e->getMessage(), 0, 300)])->save();
            throw ValidationException::withMessages(['reply' => ['Google didn\'t accept the reply: '.mb_substr($e->getMessage(), 0, 200)]]);
        }
        $review->forceFill(['reply' => $text, 'replied_at' => now(), 'replied_by_user_id' => $by->id, 'status' => Review::REPLIED, 'draft' => null, 'error' => null])->save();
        $this->audit->record('review.replied', $review, new: ['rating' => $review->rating], organization: Organization::query()->find($review->organization_id),
            label: $review->authorLabel().' ('.$review->rating.' stars)');
    }

    /**
     * The headline numbers and a month-by-month trend.
     *
     * @return array{average: ?float, count: int, google_average: ?float, google_total: ?int, recent: int, recent_average: ?float, waiting: int, response_rate: ?int, months: list<array{label: string, average: ?float, count: int}>}
     */
    public function summary(Organization $organization, int $months = 12, int $recentDays = 30): array
    {
        $all = Review::withoutGlobalScopes()->where('organization_id', $organization->id);
        $recent = (clone $all)->where('reviewed_at', '>=', now()->subDays($recentDays));
        $accounts = $this->accounts($organization);
        $googleTotal = $accounts->sum(fn (SocialAccount $a) => (int) ($a->meta['review_count'] ?? 0)) ?: null;
        $googleAverage = $googleTotal ? round($accounts->sum(fn (SocialAccount $a) => (float) ($a->meta['rating'] ?? 0) * (int) ($a->meta['review_count'] ?? 0)) / $googleTotal, 2) : null;
        $lastYear = (clone $all)->where('reviewed_at', '>=', now()->subMonths(12));
        $answered = (clone $lastYear)->whereNotNull('replied_at')->count();
        $lastYearCount = $lastYear->count();

        $start = CarbonImmutable::now()->startOfMonth()->subMonths($months - 1);
        $rows = (clone $all)->where('reviewed_at', '>=', $start)->get(['rating', 'reviewed_at'])
            ->groupBy(fn (Review $r) => $r->reviewed_at->format('Y-m'));
        $series = [];
        for ($m = $start; $m->lte(now()); $m = $m->addMonth()) {
            $month = $rows->get($m->format('Y-m'), collect());
            $series[] = ['label' => $m->format('M'), 'average' => $month->isEmpty() ? null : round($month->avg('rating'), 2), 'count' => $month->count()];
        }

        return [
            'average' => ($avg = (clone $all)->avg('rating')) !== null ? round((float) $avg, 2) : null,
            'count' => (clone $all)->count(),
            'google_average' => $googleAverage,
            'google_total' => $googleTotal,
            'recent' => (clone $recent)->count(),
            'recent_average' => ($ra = (clone $recent)->avg('rating')) !== null ? round((float) $ra, 2) : null,
            'waiting' => (clone $all)->where('status', Review::NEW)->count(),
            'response_rate' => $lastYearCount ? (int) round(100 * $answered / $lastYearCount) : null,
            'months' => $series,
        ];
    }
}

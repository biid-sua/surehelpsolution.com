<?php

namespace App\Actions\Social;

use App\Enums\SocialPostStatus;
use App\Models\MediaAsset;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\User;
use App\Notifications\SocialApprovalRequested;
use App\Services\Account\Impersonation;
use App\Services\Social\PostValidator;
use App\Services\Social\SocialApproval;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Creates or edits a post and decides where it goes next (spec §41): a draft, waiting for an owner's
 * approval, or scheduled. Publishing itself is done by the queue (SocialPublishing).
 */
class SavePost
{
    public const INTENTS = ['draft', 'schedule', 'now'];

    public function __construct(
        private readonly PostValidator $validator,
        private readonly SocialApproval $approval,
        private readonly Audit $audit,
    ) {}

    /**
     * @param  array{body: string, link_url?: ?string, accounts: list<string>, custom?: array<string, ?string>, options?: array<string, array<string, mixed>>, media?: list<string>, scheduled_at?: ?CarbonImmutable}  $data
     *
     * @throws ValidationException
     */
    public function handle(Organization $organization, ?SocialPost $post, array $data, User $actor, string $intent): SocialPost
    {
        if ($post && ! $post->status->isEditable()) {
            throw ValidationException::withMessages(['post' => ['This post has already gone out and can\'t be changed.']]);
        }

        $publishing = $intent !== 'draft';
        $accounts = $this->accounts($organization, $data['accounts'], $publishing);
        $media = $this->media($organization, $data['media'] ?? []);
        $link = filled($data['link_url'] ?? null) ? trim((string) $data['link_url']) : null;
        $when = $intent === 'now' ? CarbonImmutable::now() : ($data['scheduled_at'] ?? null);

        if ($publishing) {
            $this->checkTime($intent, $when);
            $this->checkContent($accounts, (string) $data['body'], $data['custom'] ?? [], $link, $media->count());
        }

        $byTeam = $this->approval->byOurTeam();
        $needsApproval = $publishing && $this->approval->needsApproval($organization, $actor, $post->source ?? 'manual');
        $status = match (true) {
            ! $publishing => SocialPostStatus::Draft,
            $needsApproval => SocialPostStatus::InReview,
            default => SocialPostStatus::Scheduled,
        };

        $post = DB::transaction(function () use ($organization, $post, $data, $actor, $accounts, $media, $link, $when, $status, $byTeam) {
            $post ??= new SocialPost(['organization_id' => $organization->id, 'created_by_user_id' => $actor->id]);
            $post->fill([
                'body' => trim((string) $data['body']),
                'link_url' => $link,
                'scheduled_at' => $when,
                'status' => $status,
                'submitted_at' => $status === SocialPostStatus::InReview ? now() : null,
                'approved_by_user_id' => null,
                'approved_at' => null,
            ]);
            if ($byTeam) {
                $post->source = $post->source === 'ai' ? 'ai' : 'team';
                $post->created_by_impersonator_id ??= app(Impersonation::class)->impersonator(request())?->id;
            }
            if ($status !== SocialPostStatus::Draft) {
                $post->review_note = null;
            }
            $post->save();

            $this->syncTargets($post, $accounts, $data['custom'] ?? [], $data['options'] ?? []);
            $post->media()->sync($media->values()->mapWithKeys(fn (MediaAsset $m, int $i) => [$m->id => ['position' => $i]])->all());

            return $post;
        });

        $this->audit->record($post->wasRecentlyCreated ? 'social.post_created' : 'social.post_updated', $post, new: [
            'status' => $post->status->value,
            'accounts' => $accounts->map->displayName()->all(),
            'scheduled_at' => $post->scheduled_at?->toIso8601String(),
        ], organization: $organization, label: $post->excerpt(60));

        if ($status === SocialPostStatus::InReview) {
            $owners = $organization->members()->wherePivot('status', 'active')->wherePivot('role', 'owner')->where('users.is_active', true)->get();
            Notification::send($owners, new SocialApprovalRequested($post, $byTeam ? 'the SureHelp team' : $actor->name));
        }

        return $post;
    }

    /**
     * @param  list<string>  $ulids
     * @return Collection<int, SocialAccount>
     */
    private function accounts(Organization $organization, array $ulids, bool $publishing): Collection
    {
        $accounts = SocialAccount::query()->forOrganization($organization)->whereIn('ulid', $ulids)->where('is_enabled', true)->get();

        if ($accounts->count() !== count(array_unique($ulids))) {
            throw ValidationException::withMessages(['accounts' => ['Choose accounts connected to this business.']]);
        }
        if ($publishing && $accounts->isEmpty()) {
            throw ValidationException::withMessages(['accounts' => ['Choose at least one account to post to.']]);
        }
        if ($publishing && ($lost = $accounts->first(fn (SocialAccount $a) => $a->needsReconnect()))) {
            throw ValidationException::withMessages(['accounts' => ["{$lost->displayName()} needs reconnecting before you can post to it."]]);
        }

        return $accounts;
    }

    /**
     * @param  list<string>  $ulids
     * @return Collection<int, MediaAsset>
     */
    private function media(Organization $organization, array $ulids): Collection
    {
        if ($ulids === []) {
            return collect();
        }

        $found = MediaAsset::query()->forOrganization($organization)->whereIn('ulid', $ulids)->get()->keyBy('ulid');
        if ($found->count() !== count(array_unique($ulids)) || count($ulids) > 10) {
            throw ValidationException::withMessages(['media' => ['Choose up to 10 photos from your media library.']]);
        }

        return collect($ulids)->map(fn (string $u) => $found[$u])->values();
    }

    private function checkTime(string $intent, ?CarbonImmutable $when): void
    {
        if ($intent !== 'schedule') {
            return;
        }
        if (! $when) {
            throw ValidationException::withMessages(['scheduled_at' => ['Choose when to publish.']]);
        }
        if ($when->lessThan(now()->subMinute())) {
            throw ValidationException::withMessages(['scheduled_at' => ['That time has passed. Choose a time in the future, or publish now.']]);
        }
        $max = (int) config('social.max_schedule_days', 365);
        if ($when->greaterThan(now()->addDays($max))) {
            throw ValidationException::withMessages(['scheduled_at' => ["Posts can be scheduled up to {$max} days ahead."]]);
        }
    }

    /**
     * @param  Collection<int, SocialAccount>  $accounts
     * @param  array<string, ?string>  $custom
     */
    private function checkContent(Collection $accounts, string $body, array $custom, ?string $link, int $images): void
    {
        $messages = [];

        foreach ($accounts as $account) {
            $text = filled($custom[$account->ulid] ?? null) ? (string) $custom[$account->ulid] : $body;
            foreach ($this->validator->check($account->network, $text, $link, $images)['errors'] as $error) {
                $messages[] = $account->displayName().': '.$error;
            }
        }

        if ($messages !== []) {
            throw ValidationException::withMessages(['body' => $messages]);
        }
    }

    /**
     * @param  Collection<int, SocialAccount>  $accounts
     * @param  array<string, ?string>  $custom
     * @param  array<string, array<string, mixed>>  $options
     */
    private function syncTargets(SocialPost $post, Collection $accounts, array $custom, array $options): void
    {
        $post->targets()->whereNotIn('social_account_id', $accounts->pluck('id'))->delete();

        foreach ($accounts as $account) {
            SocialPostTarget::updateOrCreate(
                ['social_post_id' => $post->id, 'social_account_id' => $account->id],
                [
                    'body' => filled($custom[$account->ulid] ?? null) ? trim((string) $custom[$account->ulid]) : null,
                    'options' => array_filter((array) ($options[$account->ulid] ?? []), fn ($v) => $v !== null && $v !== '') ?: null,
                    'status' => SocialPostTarget::PENDING,
                    'attempts' => 0,
                    'next_attempt_at' => null,
                    'last_error' => null,
                ],
            );
        }
    }
}

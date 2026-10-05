<?php

namespace App\Actions\Social;

use App\Enums\SocialPostStatus;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\User;
use App\Services\Social\SocialApproval;
use App\Support\Audit\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Approve, request changes, cancel, retry or delete a post (spec §41).
 */
class PostWorkflow
{
    public function __construct(
        private readonly SocialApproval $approval,
        private readonly Audit $audit,
    ) {}

    /**
     * An owner approves: the post is scheduled. If its planned time has passed, it goes out now.
     *
     * @throws AuthorizationException
     */
    public function approve(SocialPost $post, User $owner): SocialPost
    {
        $this->ensureOwner($post, $owner);
        $this->ensureStatus($post, [SocialPostStatus::InReview], 'Only posts waiting for approval can be approved.');

        $post->forceFill(['status' => SocialPostStatus::Scheduled, 'approved_by_user_id' => $owner->id, 'approved_at' => now(), 'review_note' => null])->save();
        $this->audit->record('social.post_approved', $post, new: ['scheduled_at' => $post->scheduled_at?->toIso8601String()], organization: $post->organization, actor: $owner, label: $post->excerpt(60));

        return $post;
    }

    /**
     * @throws AuthorizationException
     */
    public function requestChanges(SocialPost $post, User $owner, string $note): SocialPost
    {
        $this->ensureOwner($post, $owner);
        $this->ensureStatus($post, [SocialPostStatus::InReview], 'Only posts waiting for approval can be sent back.');

        $post->forceFill(['status' => SocialPostStatus::Draft, 'review_note' => mb_substr(trim($note), 0, 1000) ?: 'Please make some changes.'])->save();
        $this->audit->record('social.post_changes_requested', $post, new: ['note' => $post->review_note], organization: $post->organization, actor: $owner, label: $post->excerpt(60));

        return $post;
    }

    public function cancel(SocialPost $post, User $actor): SocialPost
    {
        $this->ensureStatus($post, [SocialPostStatus::Draft, SocialPostStatus::InReview, SocialPostStatus::Scheduled], 'This post has already gone out.');

        $post->forceFill(['status' => SocialPostStatus::Cancelled])->save();
        $post->targets()->where('status', SocialPostTarget::PENDING)->update(['status' => SocialPostTarget::CANCELLED]);
        $this->audit->record('social.post_cancelled', $post, organization: $post->organization, actor: $actor, label: $post->excerpt(60));

        return $post;
    }

    /**
     * Try the accounts that failed again, now. Published ones are left alone.
     */
    public function retry(SocialPost $post, User $actor): SocialPost
    {
        $this->ensureStatus($post, [SocialPostStatus::Failed, SocialPostStatus::PartlyPublished], 'Only failed posts can be retried.');

        $post->targets()->where('status', SocialPostTarget::FAILED)
            ->update(['status' => SocialPostTarget::PENDING, 'attempts' => 0, 'next_attempt_at' => null, 'last_error' => null]);
        $post->forceFill(['status' => SocialPostStatus::Scheduled, 'scheduled_at' => now()])->save();
        $this->audit->record('social.post_retried', $post, organization: $post->organization, actor: $actor, label: $post->excerpt(60));

        return $post;
    }

    public function delete(SocialPost $post, User $actor): void
    {
        $this->ensureStatus($post, [SocialPostStatus::Draft, SocialPostStatus::Cancelled, SocialPostStatus::Failed], 'Cancel it first; published posts stay for the record.');

        $this->audit->record('social.post_deleted', $post, old: ['body' => $post->excerpt(200)], organization: $post->organization, actor: $actor, label: $post->excerpt(60));
        $post->delete();
    }

    /**
     * @throws AuthorizationException
     */
    private function ensureOwner(SocialPost $post, User $user): void
    {
        if (! $post->organization || ! $this->approval->canApprove($post->organization, $user)) {
            throw new AuthorizationException('Only the business owner can approve posts.');
        }
    }

    /**
     * @param  list<SocialPostStatus>  $allowed
     */
    private function ensureStatus(SocialPost $post, array $allowed, string $message): void
    {
        if (! in_array($post->status, $allowed, true)) {
            throw ValidationException::withMessages(['post' => [$message]]);
        }
    }
}

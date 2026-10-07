<?php

namespace App\Services\Social;

use App\Actions\Notifications\NotifyOrganization;
use App\Enums\SocialPostStatus;
use App\Exceptions\SocialAuthorizationLost;
use App\Exceptions\SocialPostRejected;
use App\Jobs\PublishSocialTarget;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Notifications\SocialPostFailed;
use App\Services\Billing\FeatureAccess;
use App\Services\Social\Data\PublishRequest;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * SureHelp's own publishing queue (D33): due versions are claimed once, published, retried with
 * backoff when a network is busy, and summed up into the post's status.
 */
class SocialPublishing
{
    public function __construct(
        private readonly SocialManager $social,
        private readonly NotifyOrganization $notify,
        private readonly Audit $audit,
    ) {}

    /**
     * Queue every version that is due. Safe to run while another run is in progress.
     *
     * @return int versions queued
     */
    public function dispatchDue(): int
    {
        $this->recoverStuck();
        $queued = 0;

        SocialPostTarget::query()
            ->where('status', SocialPostTarget::PENDING)
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->whereHas('post', fn ($q) => $q->withoutGlobalScopes()
                ->whereIn('status', [SocialPostStatus::Scheduled->value, SocialPostStatus::Publishing->value])
                ->where(fn ($t) => $t->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now())))
            ->orderBy('id')
            ->chunkById(100, function ($targets) use (&$queued) {
                foreach ($targets as $target) {
                    $claimed = DB::table('social_post_targets')->where('id', $target->id)->where('status', SocialPostTarget::PENDING)
                        ->update(['status' => SocialPostTarget::PUBLISHING, 'updated_at' => now()]);

                    if ($claimed === 1) {
                        SocialPost::withoutGlobalScopes()->whereKey($target->social_post_id)
                            ->where('status', SocialPostStatus::Scheduled->value)->update(['status' => SocialPostStatus::Publishing->value]);
                        PublishSocialTarget::dispatch($target->id);
                        $queued++;
                    }
                }
            });

        return $queued;
    }

    /**
     * Publish one claimed version and record what happened.
     */
    public function publish(SocialPostTarget $target): void
    {
        $target->loadMissing(['post' => fn ($q) => $q->withoutGlobalScopes()->with('media'), 'account']);
        $post = $target->post;
        $account = $target->account;

        if ($target->status !== SocialPostTarget::PUBLISHING || ! $post || $post->status === SocialPostStatus::Cancelled) {
            return;
        }

        try {
            if (! $account || ! $account->is_enabled) {
                throw new SocialPostRejected('This account was removed from SureHelp.');
            }
            if ($post->organization && ! app(FeatureAccess::class)->allows($post->organization, 'social_publishing')) {
                throw new SocialPostRejected('Social publishing isn\'t included in this business\'s plan.');   // D46
            }

            $request = new PublishRequest(
                $target->text(),
                $post->link_url,
                $post->media->map(fn ($m) => ['url' => $m->publicUrl(), 'disk' => $m->disk, 'path' => $m->path, 'mime' => $m->mime, 'alt' => $m->alt_text])->values()->all(),
                (array) ($target->options ?? []),
            );

            $result = $this->social->publisher($account->network)->publish($account, $this->social->accessToken($account), $request);

            $target->forceFill([
                'status' => SocialPostTarget::PUBLISHED,
                'external_id' => $result->externalId,
                'external_url' => $result->url,
                'published_at' => now(),
                'last_error' => null,
            ])->save();
            $this->audit->record('social.published', $post, new: ['account' => $account->displayName(), 'network' => $account->network->value, 'url' => $result->url],
                organization: $post->organization, label: $post->excerpt(60));
        } catch (SocialAuthorizationLost $e) {
            $this->social->lost($account, $e->getMessage()); // only thrown once the account is known
            $this->fail($target, 'We lost access to this account. Reconnect it, then retry the post.');
        } catch (SocialPostRejected $e) {
            $this->fail($target, $e->getMessage());
        } catch (\Throwable $e) {
            report($e);
            $this->retryLater($target, $e->getMessage());
        }

        $this->summarise($post->fresh(['targets']) ?? $post);
    }

    /**
     * The post's status follows its versions.
     */
    public function summarise(SocialPost $post): void
    {
        $statuses = $post->targets->pluck('status');
        $live = $statuses->reject(fn ($s) => $s === SocialPostTarget::CANCELLED);

        if ($live->isEmpty() || $live->contains(SocialPostTarget::PENDING) || $live->contains(SocialPostTarget::PUBLISHING)) {
            return;
        }

        $published = $live->filter(fn ($s) => $s === SocialPostTarget::PUBLISHED)->count();
        $status = match (true) {
            $published === $live->count() => SocialPostStatus::Published,
            $published > 0 => SocialPostStatus::PartlyPublished,
            default => SocialPostStatus::Failed,
        };

        $changed = SocialPost::withoutGlobalScopes()->whereKey($post->id)->where('status', '!=', $status->value)
            ->update(['status' => $status->value, 'published_at' => $published > 0 ? now() : null]);

        if ($changed && $status !== SocialPostStatus::Published && $post->organization) {
            $this->notify->handle($post->organization, new SocialPostFailed($post->fresh() ?? $post), 'social.manage');
        }
    }

    /**
     * A version stuck in "publishing" (the worker died mid-call) may or may not be live. Never post it
     * again automatically: a person checks the network and retries if needed.
     */
    private function recoverStuck(): void
    {
        SocialPostTarget::query()
            ->where('status', SocialPostTarget::PUBLISHING)
            ->where('updated_at', '<', now()->subMinutes(15))
            ->each(function (SocialPostTarget $target) {
                $this->fail($target, 'Publishing was interrupted. Check the account before retrying, in case it went out.');
                if ($post = SocialPost::withoutGlobalScopes()->with('targets')->find($target->social_post_id)) {
                    $this->summarise($post);
                }
            });
    }

    private function fail(SocialPostTarget $target, string $reason): void
    {
        $target->forceFill(['status' => SocialPostTarget::FAILED, 'last_error' => mb_substr($reason, 0, 1000), 'next_attempt_at' => null])->save();
    }

    private function retryLater(SocialPostTarget $target, string $reason): void
    {
        $delays = (array) config('social.retry_minutes', [5, 15, 60]);
        $attempts = $target->attempts + 1;

        if ($attempts > count($delays)) {
            $this->fail($target, 'The network kept failing after several tries: '.mb_substr($reason, 0, 300));

            return;
        }

        $target->forceFill([
            'status' => SocialPostTarget::PENDING,
            'attempts' => $attempts,
            'next_attempt_at' => now()->addMinutes((int) $delays[$attempts - 1]),
            'last_error' => mb_substr($reason, 0, 1000),
        ])->save();
    }
}

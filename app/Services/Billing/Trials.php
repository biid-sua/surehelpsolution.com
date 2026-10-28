<?php

namespace App\Services\Billing;

use App\Actions\Billing\ManageAddons;
use App\Actions\Billing\ManageSubscription;
use App\Actions\Notifications\NotifyOrganization;
use App\Enums\SubscriptionStatus;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\TrialNotice;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Self-serve free trials (D97). A business that signed up on the website tries everything, with a
 * limited number of calls answered by our receptionists. The owner keeps their plan before the trial
 * ends; otherwise the trial ends on its last day and the account becomes read-only, with nothing
 * deleted, until they choose a plan. Trials staff set up keep converting on their own (D22).
 *
 * Request-scoped and memoised.
 */
class Trials
{
    public const ENDED = 'trial_ended';

    /** @var array<int, bool> */
    private array $ended = [];

    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly ManageSubscription $subscriptions,
        private readonly Usage $usage,
        private readonly Audit $audit,
    ) {}

    /** The trial ran out without a plan: the business can look but not change anything. */
    public function ended(Organization $organization): bool
    {
        return $this->ended[$organization->id] ??= $this->entitlements->subscription($organization) === null
            && Subscription::query()->forOrganization($organization)->latest('id')->value('ended_reason') === self::ENDED;
    }

    /** The current trial the owner still has to keep, if any. */
    public function pending(Organization $organization): ?Subscription
    {
        $subscription = $this->entitlements->subscription($organization);

        return $subscription?->awaitsConfirmation() ? $subscription : null;
    }

    /** Calls our receptionists answer during an unconfirmed trial; null means no limit. */
    public function includedCalls(): ?int
    {
        $calls = config('signup.trial_calls');

        return $calls === null || $calls === '' ? null : max(0, (int) $calls);
    }

    public function callsUsed(Subscription $subscription): int
    {
        return $this->usage->calls($subscription->organization_id, CarbonImmutable::parse($subscription->started_on->toDateString()), CarbonImmutable::now()->addMinute());
    }

    /**
     * Whether our receptionists take this business's calls now. Not after the trial ended, nor once an
     * unconfirmed trial has used its included calls: calls then go to the business's own number or
     * voicemail, as when nobody is free.
     */
    public function answersCalls(Organization $organization): bool
    {
        if ($this->ended($organization)) {
            return false;
        }
        $pending = $this->pending($organization);
        $included = $this->includedCalls();

        return ! $pending || $included === null || $this->callsUsed($pending) < $included;
    }

    public function daysLeft(Subscription $subscription, ?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();

        return max(0, (int) $today->startOfDay()->diffInDays(CarbonImmutable::parse($subscription->trial_ends_on?->toDateString() ?? $today), false));
    }

    /** The owner keeps their plan (or picks another) for after the trial. */
    public function keep(Subscription $subscription, Plan $plan, User $by): Subscription
    {
        if (! $subscription->awaitsConfirmation()) {
            throw ValidationException::withMessages(['plan' => 'Your plan is already set.']);
        }
        if ($plan->id !== $subscription->plan_id) {
            $this->subscriptions->changePlan($subscription, $plan, $by);
        }
        $subscription->forceFill(['confirmed_at' => now()])->save();
        $this->audit->record('subscription.trial_kept', $subscription, new: ['plan' => $plan->slug],
            organization: $subscription->organization, actor: $by, label: $plan->name);
        $this->forget($subscription->organization);

        return $subscription;
    }

    /** After the trial ended: a plan starts now, with its first invoice, and the account opens again. */
    public function restart(Organization $organization, Plan $plan, User $by): Subscription
    {
        if (! $this->ended($organization)) {
            throw ValidationException::withMessages(['plan' => 'Your business already has a plan.']);
        }
        $subscription = $this->subscriptions->subscribe($organization, $plan, $by, withTrial: false);
        $this->forget($organization);

        return $subscription;
    }

    /** The daily billing run: an unconfirmed trial on its end date ends (subscription state: trialing → cancelled). */
    public function end(Subscription $subscription): void
    {
        app(ManageAddons::class)->endAll($subscription->organization_id, CarbonImmutable::parse($subscription->trial_ends_on?->toDateString() ?? 'today'));
        $subscription->forceFill([
            'status' => SubscriptionStatus::Cancelled,
            'cancelled_at' => now(),
            'cancel_at_period_end' => false,
            'ended_reason' => self::ENDED,
        ])->save();
        $this->audit->record('subscription.trial_ended', $subscription, organization: $subscription->organization, label: $subscription->plan->name);
        if ($subscription->organization) {
            $this->forget($subscription->organization);
            app(NotifyOrganization::class)->handle($subscription->organization, new TrialNotice($subscription, 'ended'), 'billing.view');
        }
    }

    /**
     * The daily billing run: a reminder 3 days and 1 day before an unconfirmed trial ends, each once.
     */
    public function remind(Subscription $subscription, CarbonImmutable $today): bool
    {
        $left = $this->daysLeft($subscription, $today);
        $sent = $subscription->trial_reminders ?? [];
        $due = array_values(array_filter((array) config('signup.trial_reminder_days', [3, 1]), fn ($d) => (int) $d >= $left && ! in_array((int) $d, $sent, true)));
        if ($due === [] || $left < 1 || ! $subscription->organization) {
            return false;
        }

        // Lower days are implied when the run was missed: one reminder, never a burst.
        $subscription->forceFill(['trial_reminders' => array_values(array_unique([...$sent, ...array_map('intval', $due)]))])->save();
        app(NotifyOrganization::class)->handle($subscription->organization, new TrialNotice($subscription, 'ending', $left), 'billing.view');

        return true;
    }

    public function forget(?Organization $organization): void
    {
        if ($organization) {
            unset($this->ended[$organization->id]);
            app(FeatureAccess::class)->forget($organization);
        }
    }
}

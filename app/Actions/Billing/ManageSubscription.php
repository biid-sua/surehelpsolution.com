<?php

namespace App\Actions\Billing;

use App\Enums\SubscriptionStatus;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Starting, changing and ending a business's plan (spec §28). Changes of price take effect at the next
 * renewal, so nobody is charged twice or needs a proration calculation (docs/decisions.md D22).
 */
class ManageSubscription
{
    public function __construct(
        private readonly IssueInvoice $invoices,
        private readonly Audit $audit,
    ) {}

    /**
     * @throws ValidationException when the business already has a plan
     */
    public function subscribe(Organization $organization, Plan $plan, User $actor, ?CarbonImmutable $startOn = null, bool $withTrial = true): Subscription
    {
        if (Subscription::query()->forOrganization($organization)->current()->exists()) {
            throw ValidationException::withMessages(['plan_id' => ['This business already has a plan. Change it instead.']]);
        }
        if (! $plan->is_active) {
            throw ValidationException::withMessages(['plan_id' => ['That plan is no longer offered.']]);
        }

        $start = ($startOn ?? CarbonImmutable::today())->startOfDay();
        $trial = $withTrial && $plan->trial_days > 0;
        $periodEnd = $trial ? $start->addDays($plan->trial_days) : self::advance($start, $plan->interval);

        $subscription = Subscription::create([
            'organization_id' => $organization->id,
            'plan_id' => $plan->id,
            'status' => $trial ? SubscriptionStatus::Trialing : SubscriptionStatus::Active,
            'price_cents' => $plan->price_cents,
            'currency' => $plan->currency,
            'interval' => $plan->interval,
            'started_on' => $start->toDateString(),
            'trial_ends_on' => $trial ? $periodEnd->toDateString() : null,
            'current_period_start' => $start->toDateString(),
            'current_period_end' => $periodEnd->toDateString(),
        ]);

        $this->audit->record('subscription.created', $subscription, new: ['plan' => $plan->slug, 'price_cents' => $plan->price_cents, 'trial' => $trial],
            organization: $organization, actor: $actor, label: $plan->name);

        if (! $trial) {
            $this->invoices->forPeriod($subscription, $actor);
        }

        return $subscription;
    }

    /**
     * New plan from the next renewal. During a free trial nothing has been charged, so it switches now.
     */
    public function changePlan(Subscription $subscription, Plan $plan, User $actor): Subscription
    {
        $old = $subscription->plan->slug;

        if ($subscription->status === SubscriptionStatus::Trialing) {
            $subscription->forceFill(['plan_id' => $plan->id, 'next_plan_id' => null, 'price_cents' => $plan->price_cents, 'currency' => $plan->currency, 'interval' => $plan->interval])->save();
        } else {
            $subscription->forceFill(['next_plan_id' => $plan->id === $subscription->plan_id ? null : $plan->id])->save();
        }

        $this->audit->record('subscription.plan_changed', $subscription, old: ['plan' => $old], new: ['plan' => $plan->slug, 'effective' => $subscription->status === SubscriptionStatus::Trialing ? 'now' : 'next renewal'],
            organization: $subscription->organization, actor: $actor, label: $plan->name);

        return $subscription;
    }

    public function cancel(Subscription $subscription, User $actor, bool $atPeriodEnd = true): Subscription
    {
        if ($atPeriodEnd && $subscription->status !== SubscriptionStatus::Trialing) {
            $subscription->forceFill(['cancel_at_period_end' => true])->save();
        } else {
            $subscription->forceFill(['status' => SubscriptionStatus::Cancelled, 'cancelled_at' => now(), 'cancel_at_period_end' => false])->save();
        }

        $this->audit->record('subscription.cancelled', $subscription, new: ['at_period_end' => $subscription->cancel_at_period_end],
            organization: $subscription->organization, actor: $actor, label: $subscription->plan->name);

        return $subscription;
    }

    public function resume(Subscription $subscription, User $actor): Subscription
    {
        $subscription->forceFill(['cancel_at_period_end' => false])->save();
        $this->audit->record('subscription.resumed', $subscription, organization: $subscription->organization, actor: $actor, label: $subscription->plan->name);

        return $subscription;
    }

    /**
     * The next period start. Months keep the original billing day where it exists (Jan 31 → Feb 28 → Mar 31),
     * so short months don't make the billing day drift.
     */
    public static function advance(CarbonImmutable $from, string $interval, ?int $anchorDay = null): CarbonImmutable
    {
        $next = $interval === 'year' ? $from->addYearNoOverflow() : $from->addMonthNoOverflow();
        $anchorDay ??= $from->day;

        return $next->setDay(min($anchorDay, $next->daysInMonth));
    }
}

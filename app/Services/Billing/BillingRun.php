<?php

namespace App\Services\Billing;

use App\Actions\Billing\IssueInvoice;
use App\Actions\Billing\ManageAddons;
use App\Actions\Billing\ManageSubscription;
use App\Actions\Notifications\NotifyOrganization;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Notifications\InvoiceOverdue;
use App\Notifications\UsageAlert;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The daily billing cycle (`billing:run`). Safe to run more than once a day: every step checks
 * state first and invoices are unique per subscription period.
 *
 *  1. Trials that ended become paid plans and get their first invoice.
 *  2. Periods that ended renew (applying a scheduled plan change) and get the next invoice,
 *     or end, when cancellation was requested for the period end.
 *     The closing period's usage is recorded; calls beyond the plan and active add-ons are added
 *     to the new invoice (or, for a subscription that ends, invoiced on their own).
 *  3. Unpaid invoices past their due date mark the subscription past due and send a reminder
 *     on the due date and every 7 days after, three reminders at most.
 *  4. Businesses that reach 80% and 100% of the calls their plan includes are told, once each per period.
 */
class BillingRun
{
    public const REMINDER_EVERY_DAYS = 7;

    public const MAX_REMINDERS = 3;

    public function __construct(
        private readonly IssueInvoice $invoices,
        private readonly NotifyOrganization $notify,
        private readonly Audit $audit,
        private readonly Usage $usage,
        private readonly ManageAddons $addons,
    ) {}

    /**
     * @return array{trials_converted: int, renewed: int, ended: int, reminders: int, usage_alerts: int}
     */
    public function run(?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::today())->startOfDay();
        $report = ['trials_converted' => 0, 'renewed' => 0, 'ended' => 0, 'reminders' => 0, 'usage_alerts' => 0];

        Subscription::withoutGlobalScopes()->where('status', SubscriptionStatus::Trialing->value)
            ->whereDate('trial_ends_on', '<=', $today->toDateString())->with(['plan', 'organization'])
            ->each(function (Subscription $subscription) use (&$report) {
                $this->safely(function () use ($subscription, &$report) {
                    $start = CarbonImmutable::parse($subscription->trial_ends_on->toDateString());
                    $end = ManageSubscription::advance($start, $subscription->interval);
                    $subscription->forceFill([
                        'status' => SubscriptionStatus::Active,
                        'current_period_start' => $start->toDateString(),
                        'current_period_end' => $end->toDateString(),
                    ])->save();
                    $this->invoices->forPeriod($subscription, null,
                        $this->addons->renew($subscription->organization_id, $subscription->interval, $start, IssueInvoice::periodLabel($start, $end)));
                    $report['trials_converted']++;
                });
            });

        // A subscription several periods behind (e.g. the scheduler was down) catches up one period per loop.
        for ($pass = 0; $pass < 24; $pass++) {
            $due = Subscription::withoutGlobalScopes()->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value])
                ->whereDate('current_period_end', '<=', $today->toDateString())->with(['plan', 'nextPlan', 'organization'])->get();
            if ($due->isEmpty()) {
                break;
            }

            foreach ($due as $subscription) {
                $this->safely(function () use ($subscription, &$report) {
                    // Usage of the period that just ended, priced with the plan it was on.
                    $closing = $this->usage->closePeriod($subscription,
                        CarbonImmutable::parse($subscription->current_period_start->toDateString()),
                        CarbonImmutable::parse($subscription->current_period_end->toDateString()));

                    if ($subscription->cancel_at_period_end) {
                        if ($closing['line'] && $subscription->organization) {
                            $invoice = $this->invoices->issue($subscription->organization, [$closing['line']], $subscription->currency);
                            $closing['record']->update(['invoice_id' => $invoice->id]);
                        }
                        $this->addons->endAll($subscription->organization_id, CarbonImmutable::parse($subscription->current_period_end->toDateString()));
                        $subscription->forceFill(['status' => SubscriptionStatus::Cancelled, 'cancelled_at' => now(), 'cancel_at_period_end' => false])->save();
                        $this->audit->record('subscription.ended', $subscription, organization: $subscription->organization, label: $subscription->plan->name);
                        $report['ended']++;

                        return;
                    }

                    $plan = $subscription->nextPlan ?? $subscription->plan;
                    $start = CarbonImmutable::parse($subscription->current_period_end->toDateString());
                    $subscription->forceFill([
                        'plan_id' => $plan->id,
                        'next_plan_id' => null,
                        'price_cents' => $plan->price_cents,   // price changes reach existing customers at renewal
                        'currency' => $plan->currency,
                        'interval' => $plan->interval,
                        'current_period_start' => $start->toDateString(),
                        'current_period_end' => ManageSubscription::advance($start, $plan->interval, $subscription->started_on->day)->toDateString(),
                    ])->save();
                    $subscription->setRelation('plan', $plan);
                    $end = CarbonImmutable::parse($subscription->current_period_end->toDateString());
                    $lines = $this->addons->renew($subscription->organization_id, $plan->interval, $start, IssueInvoice::periodLabel($start, $end));
                    if ($closing['line']) {
                        array_unshift($lines, $closing['line']);
                    }
                    $invoice = $this->invoices->forPeriod($subscription, null, $lines);
                    if ($closing['line']) {
                        $closing['record']->update(['invoice_id' => $invoice->id]);
                    }
                    $report['renewed']++;
                });
            }
        }

        Invoice::withoutGlobalScopes()->where('status', InvoiceStatus::Open->value)->where('due_at', '<', now())
            ->with(['organization', 'subscription'])
            ->each(function (Invoice $invoice) use (&$report) {
                $this->safely(function () use ($invoice, &$report) {
                    if ($invoice->subscription && $invoice->subscription->status === SubscriptionStatus::Active) {
                        $invoice->subscription->forceFill(['status' => SubscriptionStatus::PastDue])->save();
                    }

                    $remind = $invoice->reminders_sent < self::MAX_REMINDERS
                        && (! $invoice->last_reminded_at || $invoice->last_reminded_at->lessThanOrEqualTo(now()->subDays(self::REMINDER_EVERY_DAYS)));
                    if (! $remind || ! $invoice->organization) {
                        return;
                    }

                    // Claim the reminder first so overlapping runs never send it twice.
                    $claimed = DB::table('invoices')->where('id', $invoice->id)->where('reminders_sent', $invoice->reminders_sent)
                        ->update(['reminders_sent' => $invoice->reminders_sent + 1, 'last_reminded_at' => now()]);
                    if ($claimed) {
                        $this->notify->handle($invoice->organization, new InvoiceOverdue($invoice), 'billing.view');
                        $report['reminders']++;
                    }
                });
            });

        Subscription::withoutGlobalScopes()->whereIn('status', [SubscriptionStatus::Trialing->value, SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value])
            ->with(['plan', 'organization'])
            ->each(function (Subscription $subscription) use (&$report) {
                $this->safely(function () use ($subscription, &$report) {
                    $meter = isset(($subscription->plan->limits ?? [])['calls']) && $subscription->organization ? $this->usage->current($subscription) : null;
                    if (! $meter || $meter['percent'] === null) {
                        return;
                    }

                    $period = $subscription->current_period_start->toDateString();
                    $sent = ($subscription->usage_alerts['period'] ?? null) === $period ? ($subscription->usage_alerts['levels'] ?? []) : [];
                    $reached = array_values(array_filter(Usage::ALERT_LEVELS, fn (int $level) => $meter['percent'] >= $level));
                    $new = array_diff($reached, $sent);
                    if ($new === []) {
                        return;
                    }

                    // One notice for the highest new level; lower ones are implied.
                    $subscription->forceFill(['usage_alerts' => ['period' => $period, 'levels' => array_values(array_unique([...$sent, ...$reached]))]])->save();
                    $this->notify->handle($subscription->organization, new UsageAlert($subscription, max($new), $meter), 'billing.view');
                    $report['usage_alerts']++;
                });
            });

        return $report;
    }

    private function safely(callable $step): void
    {
        try {
            DB::transaction($step);
        } catch (\Throwable $e) {
            report($e);   // one broken subscription never stops everyone else's billing
        }
    }
}

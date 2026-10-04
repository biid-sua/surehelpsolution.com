<?php

namespace App\Services\Billing;

use App\Actions\Billing\IssueInvoice;
use App\Actions\Billing\ManageSubscription;
use App\Actions\Notifications\NotifyOrganization;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Notifications\InvoiceOverdue;
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
 *  3. Unpaid invoices past their due date mark the subscription past due and send a reminder
 *     on the due date and every 7 days after, three reminders at most.
 */
class BillingRun
{
    public const REMINDER_EVERY_DAYS = 7;

    public const MAX_REMINDERS = 3;

    public function __construct(
        private readonly IssueInvoice $invoices,
        private readonly NotifyOrganization $notify,
        private readonly Audit $audit,
    ) {}

    /**
     * @return array{trials_converted: int, renewed: int, ended: int, reminders: int}
     */
    public function run(?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::today())->startOfDay();
        $report = ['trials_converted' => 0, 'renewed' => 0, 'ended' => 0, 'reminders' => 0];

        Subscription::withoutGlobalScopes()->where('status', SubscriptionStatus::Trialing->value)
            ->whereDate('trial_ends_on', '<=', $today->toDateString())->with(['plan', 'organization'])
            ->each(function (Subscription $subscription) use (&$report) {
                $this->safely(function () use ($subscription, &$report) {
                    $start = CarbonImmutable::parse($subscription->trial_ends_on->toDateString());
                    $subscription->forceFill([
                        'status' => SubscriptionStatus::Active,
                        'current_period_start' => $start->toDateString(),
                        'current_period_end' => ManageSubscription::advance($start, $subscription->interval)->toDateString(),
                    ])->save();
                    $this->invoices->forPeriod($subscription);
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
                    if ($subscription->cancel_at_period_end) {
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
                    $this->invoices->forPeriod($subscription);
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

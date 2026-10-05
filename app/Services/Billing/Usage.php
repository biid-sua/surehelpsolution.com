<?php

namespace App\Services\Billing;

use App\Models\CallLog;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\UsageRecord;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Usage against the plan (task.md BIL-03, BIL-05). For now the metric is calls answered; minutes,
 * texts and AI usage join it once telephony and AI exist. Spam calls never count.
 */
class Usage
{
    public const METRIC_CALLS = 'calls';

    /** Usage alert levels, in percent of what the plan includes (BIL-11). */
    public const ALERT_LEVELS = [80, 100];

    public function calls(Organization|int $organization, CarbonImmutable $from, CarbonImmutable $until): int
    {
        return CallLog::withoutGlobalScopes()->forOrganization($organization)
            ->where('created_at', '>=', $from)->where('created_at', '<', $until)
            ->where('status', '!=', 'spam')->count();
    }

    /**
     * The current period's meter for the billing page.
     *
     * @return array{used: int, included: int|null, percent: int|null, extra: int, extra_cents: int, unit_cents: int, from: CarbonImmutable, until: CarbonImmutable}|null
     */
    public function current(Subscription $subscription): ?array
    {
        $from = CarbonImmutable::parse($subscription->current_period_start->toDateString());
        $until = CarbonImmutable::parse($subscription->current_period_end->toDateString());
        $limits = $subscription->plan->limits ?? [];
        $included = isset($limits['calls']) ? (int) $limits['calls'] : null;
        $unit = (int) ($limits['extra_call_cents'] ?? 0);
        $used = $this->calls($subscription->organization_id, $from, $until);
        $extra = $included === null ? 0 : max(0, $used - $included);

        return [
            'used' => $used,
            'included' => $included,
            'percent' => $included ? (int) floor(100 * $used / $included) : null,
            'extra' => $extra,
            'extra_cents' => $extra * $unit,
            'unit_cents' => $unit,
            'from' => $from,
            'until' => $until,
        ];
    }

    /**
     * Records the usage of a period that just ended and returns the invoice line for calls beyond
     * the plan, if any. Recorded once: a second call returns the same answer without a new row.
     *
     * @return array{record: UsageRecord, line: array{description: string, quantity: int, unit_cents: int}|null}
     */
    public function closePeriod(Subscription $subscription, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $limits = $subscription->plan->limits ?? [];
        $included = isset($limits['calls']) ? (int) $limits['calls'] : null;
        $unit = (int) ($limits['extra_call_cents'] ?? 0);

        $record = UsageRecord::withoutGlobalScopes()->firstOrCreate(
            ['subscription_id' => $subscription->id, 'metric' => self::METRIC_CALLS, 'period_start' => $from->toDateString()],
            (function () use ($subscription, $from, $until, $included, $unit) {
                $used = $this->calls($subscription->organization_id, $from, $until);

                return [
                    'organization_id' => $subscription->organization_id,
                    'period_end' => $until->toDateString(),
                    'quantity' => $used,
                    'included' => $included,
                    'overage' => $included === null ? 0 : max(0, $used - $included),
                    'unit_cents' => $unit,
                ];
            })(),
        );

        $line = null;
        if ($record->wasRecentlyCreated && $record->overage > 0 && $record->unit_cents > 0) {
            $label = $from->format('M j').' – '.$until->subDay()->format('M j, Y');
            $line = ['description' => "Extra calls · {$label} ({$record->quantity} answered, {$record->included} included; ".Money::format($record->unit_cents, $subscription->currency).' each)',
                'quantity' => $record->overage, 'unit_cents' => $record->unit_cents];
        }

        return ['record' => $record, 'line' => $line];
    }
}

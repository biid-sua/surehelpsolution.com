<?php

namespace App\Services\Sales;

use App\Models\Plan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * What the sales chat may say about plans (D98): the plans on the website, from Admin › Billing ›
 * Plans, so the chat and the pricing section never disagree. Falls back to config('sales.plans')
 * on an install that has no website plans yet.
 */
class SalesPlans
{
    /**
     * @return list<array{name: string, monthly: int|float|null, annual_monthly: int|float|null, minutes: string, best_for: string, includes: string, extra: string}>
     */
    public function all(): array
    {
        $plans = rescue(fn () => Schema::hasTable('plans') ? Plan::query()->onWebsite()->ordered()->get() : collect(), collect(), report: false);
        if ($plans->isEmpty()) {
            return array_values(config('sales.plans', []));
        }

        return $plans->map(function (Plan $plan) {
            $lines = $plan->highlights();
            $dollars = fn (?int $cents) => $cents === null ? null : ($cents % 100 === 0 ? intdiv($cents, 100) : round($cents / 100, 2));

            return [
                'name' => $plan->name,
                'monthly' => $plan->contact_only ? null : $dollars($plan->interval === 'year' ? $plan->monthlyCents() : $plan->price_cents),
                'annual_monthly' => $plan->contact_only ? null : $dollars($plan->annual_monthly_cents),
                'minutes' => Str::lower($lines[0] ?? 'calls answered 24/7'),
                'best_for' => Str::of((string) $plan->tagline)->after('Best for ')->lcfirst()->value() ?: 'businesses like yours',
                'includes' => Str::lower(implode(', ', array_slice($lines, 1))) ?: 'our 24/7 answering service',
                'extra' => isset($plan->limits['extra_call_cents']) ? '$'.number_format($plan->limits['extra_call_cents'] / 100, 2).' per extra call' : ($plan->contact_only ? 'tailored' : 'no extra-call charge listed'),
            ];
        })->values()->all();
    }

    /** The biggest yearly saving, in percent, or null when no plan offers a yearly price. */
    public function annualSaving(): ?int
    {
        $savings = collect($this->all())->filter(fn ($p) => $p['monthly'] && $p['annual_monthly'])
            ->map(fn ($p) => (int) round((1 - $p['annual_monthly'] / $p['monthly']) * 100));

        return $savings->max();
    }
}

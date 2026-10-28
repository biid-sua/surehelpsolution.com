<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A business's plan over time (spec §28). Dates are calendar dates; a period runs from
 * current_period_start up to (not including) current_period_end.
 *
 * @property SubscriptionStatus $status
 * @property Carbon $started_on
 * @property Carbon|null $trial_ends_on
 * @property Carbon $current_period_start
 * @property Carbon $current_period_end
 * @property Carbon|null $cancelled_at
 * @property array{period?: string, levels?: list<int>}|null $usage_alerts usage alerts sent in the current period
 * @property bool $confirm_by_trial_end a self-serve trial (D97): it ends unless the owner keeps the plan
 * @property Carbon|null $confirmed_at when the owner chose to keep the plan after the trial
 * @property string|null $ended_reason trial_ended when an unconfirmed trial ran out
 * @property list<int>|null $trial_reminders days-left reminders already sent
 */
class Subscription extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'plan_id', 'next_plan_id', 'status', 'price_cents', 'currency', 'interval', 'started_on',
        'trial_ends_on', 'current_period_start', 'current_period_end', 'cancel_at_period_end', 'cancelled_at', 'usage_alerts',
        'confirm_by_trial_end', 'confirmed_at', 'ended_reason', 'trial_reminders',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'price_cents' => 'integer',
            'started_on' => 'date',
            'trial_ends_on' => 'date',
            'current_period_start' => 'date',
            'current_period_end' => 'date',
            'usage_alerts' => 'array',
            'cancel_at_period_end' => 'boolean',
            'cancelled_at' => 'datetime',
            'confirm_by_trial_end' => 'boolean',
            'confirmed_at' => 'datetime',
            'trial_reminders' => 'array',
        ];
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function nextPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'next_plan_id');
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * @param  Builder<Subscription>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->where('status', '!=', SubscriptionStatus::Cancelled->value);
    }

    /** A self-serve trial the owner hasn't kept yet (D97): it ends on its last day. */
    public function awaitsConfirmation(): bool
    {
        return $this->status === SubscriptionStatus::Trialing && $this->confirm_by_trial_end && $this->confirmed_at === null;
    }

    public function priceLabel(): string
    {
        return Money::format($this->price_cents, $this->currency).'/'.$this->interval;
    }

    /** The day the next invoice is issued (trial end while trialing). */
    public function nextBillingDate(): ?Carbon
    {
        return match (true) {
            $this->status === SubscriptionStatus::Cancelled, $this->cancel_at_period_end => null,
            $this->status === SubscriptionStatus::Trialing => $this->trial_ends_on,
            default => $this->current_period_end,
        };
    }
}

<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\StoresUtc;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An invoice (spec §28–29). Issued invoices are never edited, only paid or voided.
 *
 * @property InvoiceStatus $status
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property Carbon $issued_at
 * @property Carbon $due_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $voided_at
 * @property Carbon|null $last_reminded_at
 * @property Carbon|null $client_reported_paid_at
 * @property array<string, mixed>|null $billing_details
 */
class Invoice extends Model
{
    use BelongsToOrganization, StoresUtc;

    protected $fillable = [
        'number', 'organization_id', 'subscription_id', 'status', 'currency', 'subtotal_cents', 'total_cents', 'amount_paid_cents',
        'period_start', 'period_end', 'issued_at', 'due_at', 'paid_at', 'voided_at', 'payment_url', 'billing_details', 'notes',
        'last_reminded_at', 'reminders_sent', 'client_reported_paid_at', 'client_payment_note',
    ];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'subtotal_cents' => 'integer',
            'total_cents' => 'integer',
            'amount_paid_cents' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'issued_at' => 'datetime',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
            'last_reminded_at' => 'datetime',
            'client_reported_paid_at' => 'datetime',
            'billing_details' => 'array',
            'reminders_sent' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Invoice $invoice) {
            $invoice->ulid ??= (string) Str::ulid();
        });
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return HasMany<InvoiceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('id');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('received_at');
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    public function balanceCents(): int
    {
        return max(0, $this->total_cents - $this->amount_paid_cents);
    }

    public function isOverdue(): bool
    {
        return $this->status === InvoiceStatus::Open && $this->due_at->isPast();
    }

    public function money(int $cents): string
    {
        return Money::format($cents, $this->currency);
    }

    /** "Oct 1 – Oct 31, 2026" (the period end is exclusive, so the last day shown is the day before). */
    public function periodLabel(): ?string
    {
        if (! $this->period_start || ! $this->period_end) {
            return null;
        }
        $last = $this->period_end->copy()->subDay();

        return $this->period_start->format($this->period_start->year === $last->year ? 'M j' : 'M j, Y').' – '.$last->format('M j, Y');
    }

    /**
     * @param  Builder<Invoice>  $query
     */
    public function scopeOutstanding(Builder $query): void
    {
        $query->where('status', InvoiceStatus::Open->value);
    }
}

<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\StoresUtc;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Money received (spec §28: payment history). Never holds card or bank details.
 *
 * @property PaymentMethod $method
 * @property Carbon $received_at
 */
class Payment extends Model
{
    use BelongsToOrganization, StoresUtc;

    protected $fillable = ['organization_id', 'invoice_id', 'amount_cents', 'currency', 'method', 'provider', 'reference', 'received_at', 'recorded_by_user_id', 'notes'];

    protected function casts(): array
    {
        return ['method' => PaymentMethod::class, 'amount_cents' => 'integer', 'received_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (Payment $payment) {
            $payment->ulid ??= (string) Str::ulid();
        });
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function amountLabel(): string
    {
        return Money::format($this->amount_cents, $this->currency);
    }
}

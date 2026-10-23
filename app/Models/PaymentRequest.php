<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\StoresUtc;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A request for a customer to pay the business: a deposit for a booking or any amount (D85).
 *
 * @property Carbon|null $paid_at
 * @property Carbon|null $checkout_expires_at
 * @property list<string>|null $sent_via
 */
class PaymentRequest extends Model
{
    use BelongsToOrganization, StoresUtc;

    public const OPEN = 'open';

    public const PAID = 'paid';

    public const CANCELLED = 'cancelled';

    public const REFUNDED = 'refunded';

    protected $fillable = [
        'organization_id', 'customer_id', 'appointment_id', 'kind', 'description', 'amount_cents', 'currency', 'status', 'token',
        'checkout_session_id', 'checkout_url', 'checkout_expires_at', 'payment_intent_id', 'fee_cents', 'paid_at', 'sent_via', 'created_by_user_id',
    ];

    protected $hidden = ['token', 'checkout_url'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'fee_cents' => 'integer', 'paid_at' => 'datetime', 'checkout_expires_at' => 'datetime', 'sent_via' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(function (PaymentRequest $request) {
            $request->ulid ??= (string) Str::ulid();
            $request->token ??= Str::random(32);
        });
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Appointment, $this> */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function link(): string
    {
        return route('pay.show', $this->token);
    }

    public function amount(): string
    {
        return Money::format($this->amount_cents, strtoupper($this->currency));
    }
}

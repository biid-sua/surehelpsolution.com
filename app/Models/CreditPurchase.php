<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A pack of AI credits a business bought (D100). pending → granted when its invoice is paid, or
 * cancelled when the invoice is voided.
 *
 * @property Carbon|null $granted_at
 */
class CreditPurchase extends Model
{
    use BelongsToOrganization;

    public const STATUSES = ['pending', 'granted', 'cancelled'];

    protected $fillable = ['organization_id', 'credits', 'price_cents', 'currency', 'invoice_id', 'status', 'granted_at', 'user_id'];

    protected $attributes = ['status' => 'pending', 'currency' => 'USD'];

    protected function casts(): array
    {
        return ['credits' => 'integer', 'price_cents' => 'integer', 'granted_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (CreditPurchase $p) => $p->ulid ??= (string) Str::ulid());
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}

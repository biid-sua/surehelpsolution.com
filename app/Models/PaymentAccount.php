<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A business's own Stripe account, connected through Stripe Connect (D85).
 *
 * @property Carbon|null $connected_at
 */
class PaymentAccount extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'stripe_account_id', 'charges_enabled', 'details_submitted', 'currency', 'deposit_enabled', 'deposit_cents', 'connected_at'];

    protected function casts(): array
    {
        return ['charges_enabled' => 'boolean', 'details_submitted' => 'boolean', 'deposit_enabled' => 'boolean', 'deposit_cents' => 'integer', 'connected_at' => 'datetime'];
    }

    public function isReady(): bool
    {
        return $this->charges_enabled;
    }
}

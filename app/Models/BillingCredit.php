<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * Money a business has to spend on its SureHelp invoices, such as a referral reward (D90).
 */
class BillingCredit extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'referral_id', 'reason', 'amount_cents', 'used_cents', 'currency'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'used_cents' => 'integer'];
    }

    public function remaining(): int
    {
        return max(0, $this->amount_cents - $this->used_cents);
    }
}

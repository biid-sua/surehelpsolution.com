<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One business referring another (D90).
 *
 * @property Carbon|null $rewarded_at
 */
class Referral extends Model
{
    protected $fillable = ['referrer_organization_id', 'referred_organization_id', 'status', 'credit_cents', 'rewarded_at'];

    protected function casts(): array
    {
        return ['rewarded_at' => 'datetime', 'credit_cents' => 'integer'];
    }

    /** @return BelongsTo<Organization, $this> */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'referrer_organization_id');
    }

    /** @return BelongsTo<Organization, $this> */
    public function referred(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'referred_organization_id');
    }
}

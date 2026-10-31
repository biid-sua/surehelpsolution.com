<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One customer in a campaign (D116): how they were reached, so nobody gets it twice.
 *
 * @property list<string>|null $channels
 * @property Carbon|null $sent_at
 */
class CampaignRecipient extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['campaign_id', 'organization_id', 'customer_id', 'channels', 'sent_at'];

    protected function casts(): array
    {
        return ['channels' => 'array', 'sent_at' => 'datetime'];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}

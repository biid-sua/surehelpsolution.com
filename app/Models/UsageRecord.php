<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What a business used in one finished billing period, and what was charged beyond the plan
 * (task.md BIL-03). Written once per subscription, metric and period.
 *
 * @property Carbon $period_start
 * @property Carbon $period_end
 */
class UsageRecord extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'subscription_id', 'metric', 'period_start', 'period_end', 'quantity', 'included', 'overage', 'unit_cents', 'invoice_id'];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'quantity' => 'integer',
            'included' => 'integer',
            'overage' => 'integer',
            'unit_cents' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}

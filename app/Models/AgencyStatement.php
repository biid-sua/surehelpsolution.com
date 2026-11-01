<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One month of an agency's share (D118). Statuses: due → paid (docs/state-machines.md).
 *
 * @property Carbon $month
 * @property Carbon|null $paid_at
 */
class AgencyStatement extends Model
{
    public const DUE = 'due';

    public const PAID = 'paid';

    protected $fillable = ['agency_id', 'month', 'clients_paid_cents', 'commission_percent', 'share_cents', 'status', 'paid_at', 'paid_by_user_id', 'reference'];

    protected function casts(): array
    {
        return ['month' => 'date', 'clients_paid_cents' => 'integer', 'share_cents' => 'integer', 'paid_at' => 'datetime'];
    }

    /** @return BelongsTo<Agency, $this> */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }
}

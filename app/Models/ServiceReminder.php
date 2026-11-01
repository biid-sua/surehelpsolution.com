<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A service reminder sent for one completed job (D116), so each job is reminded about once.
 *
 * @property list<string> $channels
 * @property Carbon $sent_at
 */
class ServiceReminder extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'appointment_id', 'customer_id', 'service_id', 'channels', 'sent_at'];

    protected function casts(): array
    {
        return ['channels' => 'array', 'sent_at' => 'datetime'];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<BusinessService, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(BusinessService::class, 'service_id');
    }
}

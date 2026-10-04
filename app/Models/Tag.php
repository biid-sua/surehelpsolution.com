<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A label a business puts on customers, e.g. "VIP", "Repeat" (spec §12).
 */
class Tag extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'name', 'color'];

    /**
     * @return BelongsToMany<Customer, $this>
     */
    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class);
    }
}

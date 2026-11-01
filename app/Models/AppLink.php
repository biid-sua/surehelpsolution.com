<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * The same record in SureHelp and in a connected app (D110): a customer and its QuickBooks customer,
 * a payment and its QuickBooks payment. Stops anything being sent twice.
 */
class AppLink extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'app_connection_id', 'local_type', 'local_id', 'external_id'];

    protected function casts(): array
    {
        return ['local_id' => 'integer'];
    }
}

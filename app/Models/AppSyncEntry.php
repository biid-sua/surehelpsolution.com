<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * One line of a connected app's history (D110): what was sent, received, or failed.
 *
 * @property Carbon $created_at
 */
class AppSyncEntry extends Model
{
    use BelongsToOrganization;

    public const UPDATED_AT = null;

    public const LABELS = [
        'customer_sent' => 'Customer sent',
        'customer_received' => 'Customer brought in',
        'customer_matched' => 'Customer matched',
        'payment_sent' => 'Payment sent',
        'payment_voided' => 'Payment voided (refunded)',
        'booking_sent' => 'Booking sent as a request',
        'job_sent' => 'Booking sent as a job',
        'failed' => 'Didn\'t go through',
    ];

    protected $fillable = ['organization_id', 'app_connection_id', 'action', 'label', 'error', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}

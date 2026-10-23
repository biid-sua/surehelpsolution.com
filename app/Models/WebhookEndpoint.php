<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Where a business wants events sent (D87).
 *
 * @property list<string> $events
 * @property Carbon|null $last_delivery_at
 */
class WebhookEndpoint extends Model
{
    use BelongsToOrganization;

    /** Events a business can subscribe to. */
    public const EVENTS = [
        'appointment.booked' => 'Appointment booked',
        'appointment.rescheduled' => 'Appointment moved',
        'appointment.completed' => 'Appointment completed',
        'appointment.no_show' => 'Customer didn\'t show up',
        'appointment.cancelled' => 'Appointment cancelled',
        'call.logged' => 'Call answered and logged',
        'call.missed' => 'Call missed',
        'conversation.started' => 'New conversation (first message)',
        'customer.created' => 'New customer or lead',
        'escalation.raised' => 'Escalation raised',
        'task.overdue' => 'Task overdue',
        'review.received' => 'New Google review',
        'survey.answered' => 'Satisfaction survey answered',
        'payment.paid' => 'Customer payment received',
    ];

    /** Stop after this many failures in a row. */
    public const MAX_FAILURES = 20;

    protected $fillable = ['organization_id', 'url', 'secret', 'events', 'is_active', 'failures', 'disabled_reason', 'last_delivery_at'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['secret' => 'encrypted', 'events' => 'array', 'is_active' => 'boolean', 'last_delivery_at' => 'datetime', 'failures' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (WebhookEndpoint $endpoint) {
            $endpoint->ulid ??= (string) Str::ulid();
            $endpoint->secret ??= 'whsec_'.Str::random(40);
        });
    }

    /** @return HasMany<WebhookDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function wants(string $event): bool
    {
        return $this->is_active && in_array($event, $this->events, true);
    }
}

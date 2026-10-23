<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One event sent (or being sent) to a webhook endpoint (D87).
 *
 * @property array<string, mixed> $payload
 * @property Carbon|null $delivered_at
 */
class WebhookDelivery extends Model
{
    protected $fillable = ['webhook_endpoint_id', 'event', 'payload', 'status', 'attempts', 'response_code', 'error', 'delivered_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'delivered_at' => 'datetime', 'attempts' => 'integer', 'response_code' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(fn (WebhookDelivery $d) => $d->ulid ??= (string) Str::ulid());
    }

    /** @return BelongsTo<WebhookEndpoint, $this> */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}

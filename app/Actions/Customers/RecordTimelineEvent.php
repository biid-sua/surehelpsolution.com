<?php

namespace App\Actions\Customers;

use App\Enums\TimelineEventType;
use App\Models\Customer;
use App\Models\CustomerTimelineEvent;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Adds an entry to a customer's timeline and keeps "last activity" current (spec §13).
 */
class RecordTimelineEvent
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function handle(
        Customer $customer,
        TimelineEventType $type,
        string $title,
        ?string $body = null,
        ?Model $subject = null,
        array $meta = [],
        ?int $actorId = null,
        ?CarbonInterface $occurredAt = null,
    ): CustomerTimelineEvent {
        $occurredAt ??= now();

        $event = CustomerTimelineEvent::create([
            'organization_id' => $customer->organization_id,
            'customer_id' => $customer->id,
            'type' => $type,
            'title' => Str::limit($title, 250, ''),
            'body' => $body,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'meta' => $meta ?: null,
            'actor_id' => $actorId,
            'occurred_at' => $occurredAt,
        ]);

        if (! $customer->last_activity_at || $customer->last_activity_at->lessThan($occurredAt)) {
            $customer->forceFill(['last_activity_at' => $occurredAt])->saveQuietly();
        }

        return $event;
    }
}

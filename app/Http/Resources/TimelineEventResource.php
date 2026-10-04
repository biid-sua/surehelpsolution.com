<?php

namespace App\Http\Resources;

use App\Models\CustomerTimelineEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CustomerTimelineEvent
 */
class TimelineEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'title' => $this->title,
            'body' => $this->body,
            'call_id' => $this->meta['call_id'] ?? null,
            'occurred_at' => $this->occurred_at->toIso8601String(),
        ];
    }
}

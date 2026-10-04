<?php

namespace App\Http\Resources;

use App\Models\Escalation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Escalation
 */
class EscalationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'priority' => $this->priority->value,
            'status' => $this->status->value,
            'reason' => $this->reason,
            'details' => $this->details,
            'source' => $this->source,
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? ['id' => $this->customer->ulid, 'name' => $this->customer->fullName()] : null),
            'call_id' => $this->whenLoaded('call', fn () => $this->call?->call_id),
            'assigned_to' => $this->whenLoaded('assignee', fn () => $this->assignee ? ['id' => $this->assignee->id, 'name' => $this->assignee->name] : null),
            'acknowledged_at' => $this->acknowledged_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'resolution_notes' => $this->resolution_notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

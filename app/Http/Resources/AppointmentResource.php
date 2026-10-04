<?php

namespace App\Http\Resources;

use App\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Appointment
 */
class AppointmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'title' => $this->title,
            'status' => $this->status->value,
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at->toIso8601String(),
            'local_start' => $this->localStart()->format('Y-m-d\TH:i'),
            'timezone' => $this->organization?->timezoneOrDefault() ?? $this->timezone,
            'duration_minutes' => $this->durationMinutes(),
            'source' => $this->source,
            'notes' => $this->notes,
            'address' => $this->address,
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? ['id' => $this->customer->ulid, 'name' => $this->customer->fullName(), 'phone' => $this->customer->displayPhone()] : null),
            'service' => $this->whenLoaded('service', fn () => $this->service ? ['id' => $this->service->id, 'name' => $this->service->name] : null),
            'location' => $this->whenLoaded('location', fn () => $this->location ? ['id' => $this->location->id, 'name' => $this->location->name] : null),
            'call_id' => $this->whenLoaded('call', fn () => $this->call?->call_id),
            'cancellation_reason' => $this->cancellation_reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

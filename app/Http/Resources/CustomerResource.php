<?php

namespace App\Http\Resources;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Customer
 */
class CustomerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'name' => $this->fullName(),
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'company' => $this->company,
            'phone' => $this->displayPhone(),
            'phone_e164' => $this->phone_e164,
            'email' => $this->email,
            'address' => $this->singleLineAddress(),
            'status' => $this->status->value,
            'source' => $this->source,
            'preferred_contact' => $this->preferred_contact,
            'sms_consent' => $this->sms_consent,
            'email_consent' => $this->email_consent,
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->pluck('name')),
            'last_activity_at' => $this->last_activity_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

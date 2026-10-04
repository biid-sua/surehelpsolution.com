<?php

namespace App\Services\Calendar\Data;

use Carbon\CarbonImmutable;

/**
 * A provider push subscription (Google watch channel / Microsoft Graph subscription).
 */
final class PushChannel
{
    public function __construct(
        public readonly string $calendarId,
        public readonly string $id,
        public readonly ?string $resourceId,
        public readonly CarbonImmutable $expiresAt,
    ) {}

    /**
     * @return array{calendar_id: string, id: string, resource_id: ?string, expires_at: string}
     */
    public function toArray(): array
    {
        return ['calendar_id' => $this->calendarId, 'id' => $this->id, 'resource_id' => $this->resourceId, 'expires_at' => $this->expiresAt->toIso8601String()];
    }
}

<?php

namespace App\Services\Calendar\Data;

/**
 * A calendar inside a connected account.
 */
final class ExternalCalendar
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly bool $primary = false,
        public readonly bool $canWrite = true,
    ) {}

    /**
     * @return array{id: string, name: string, primary: bool, can_write: bool}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'primary' => $this->primary, 'can_write' => $this->canWrite];
    }
}

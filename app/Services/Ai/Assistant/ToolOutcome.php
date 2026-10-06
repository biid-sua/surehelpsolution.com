<?php

namespace App\Services\Ai\Assistant;

/**
 * What the assistant's tools did during one reply.
 */
final class ToolOutcome
{
    public bool $handedOver = false;

    public ?int $bookedAppointmentId = null;

    public ?int $escalationId = null;

    /** @var list<int> */
    public array $taskIds = [];
}

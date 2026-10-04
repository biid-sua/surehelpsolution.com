<?php

namespace App\Services\Calendar\Data;

/**
 * Where our copy of an appointment lives in an external calendar, and its version (etag),
 * so we never overwrite a change someone made there (spec §16).
 */
final class EventRef
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $etag = null,
    ) {}
}

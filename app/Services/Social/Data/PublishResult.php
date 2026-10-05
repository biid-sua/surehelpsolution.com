<?php

namespace App\Services\Social\Data;

final class PublishResult
{
    public function __construct(
        public readonly string $externalId,
        public readonly ?string $url = null,
    ) {}
}

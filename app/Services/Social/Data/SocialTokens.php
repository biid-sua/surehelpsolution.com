<?php

namespace App\Services\Social\Data;

use Carbon\CarbonImmutable;

final class SocialTokens
{
    public function __construct(
        public readonly string $accessToken,
        public readonly ?string $refreshToken = null,
        public readonly ?CarbonImmutable $expiresAt = null,
    ) {}
}

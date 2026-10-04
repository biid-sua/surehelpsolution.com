<?php

namespace App\Services\Calendar\Data;

use Carbon\CarbonImmutable;

final class OAuthTokens
{
    public function __construct(
        public readonly string $accessToken,
        public readonly ?string $refreshToken,
        public readonly CarbonImmutable $expiresAt,
        public readonly ?string $scope = null,
    ) {}
}

<?php

namespace App\Services\Social\Data;

use App\Enums\SocialNetwork;
use Carbon\CarbonImmutable;

/**
 * An account the person can publish to, found right after they connected (a Page, an Instagram account, ...).
 */
final class DiscoveredAccount
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly SocialNetwork $network,
        public readonly string $externalId,
        public readonly string $name,
        public readonly string $accessToken,
        public readonly ?string $refreshToken = null,
        public readonly ?CarbonImmutable $expiresAt = null,
        public readonly ?string $handle = null,
        public readonly ?string $avatarUrl = null,
        public readonly array $meta = [],
    ) {}
}

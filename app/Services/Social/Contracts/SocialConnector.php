<?php

namespace App\Services\Social\Contracts;

use App\Enums\SocialNetwork;
use App\Exceptions\SocialAuthorizationLost;
use App\Models\SocialAccount;
use App\Services\Social\Data\DiscoveredAccount;
use App\Services\Social\Data\SocialTokens;

/**
 * One OAuth app SureHelp registered with a network owner (Meta, LinkedIn, Google). It signs the
 * business in and finds the accounts they can publish to.
 */
interface SocialConnector
{
    public function key(): string;

    public function label(): string;

    /** @return list<SocialNetwork> */
    public function networks(): array;

    /** SureHelp has credentials (and, where needed, approval) for this app. */
    public function isConfigured(): bool;

    public function authorizationUrl(string $redirectUri, string $state): string;

    public function exchangeCode(string $code, string $redirectUri): SocialTokens;

    /** @return list<DiscoveredAccount> */
    public function discover(SocialTokens $tokens): array;

    /**
     * New tokens for an account whose access token is about to expire.
     *
     * @throws SocialAuthorizationLost
     */
    public function refresh(SocialAccount $account): SocialTokens;
}

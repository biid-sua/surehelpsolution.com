<?php

namespace App\Services\Sites\Domains;

/**
 * Our own server issues certificates (D101): Caddy's on-demand TLS, which asks
 * /sites/domain-allowed before issuing one, or certificates added in Forge. Once the DNS points here,
 * the domain is ready.
 */
class ManualDomainProvider implements DomainProvider
{
    public function name(): string
    {
        return 'manual';
    }

    public function add(string $host): ?string
    {
        return null;
    }

    public function status(string $host, ?string $reference): array
    {
        return ['ready' => true, 'error' => null];
    }

    public function remove(string $host, ?string $reference): void {}
}

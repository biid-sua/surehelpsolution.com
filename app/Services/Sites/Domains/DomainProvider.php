<?php

namespace App\Services\Sites\Domains;

/**
 * Whoever issues the HTTPS certificate for a business's own domain (D101). The app checks the DNS;
 * the provider makes the domain work over HTTPS.
 */
interface DomainProvider
{
    public function name(): string;

    /**
     * Starts serving a domain. Returns the provider's reference for it (or null when there's none).
     *
     * @throws DomainProviderFailed
     */
    public function add(string $host): ?string;

    /**
     * Whether the certificate is issued, and if not, why (null while it's simply still working).
     *
     * @return array{ready: bool, error: ?string}
     *
     * @throws DomainProviderFailed
     */
    public function status(string $host, ?string $reference): array;

    public function remove(string $host, ?string $reference): void;
}

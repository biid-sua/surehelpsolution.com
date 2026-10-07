<?php

namespace Tests\Support;

use App\Services\Websites\DnsResolver;

/**
 * Answers DNS lookups in tests: host => list of IPs, host => list of TXT records.
 */
class FakeDnsResolver extends DnsResolver
{
    /**
     * @param  array<string, list<string>>  $a
     * @param  array<string, list<string>>  $txt
     */
    public function __construct(public array $a = [], public array $txt = []) {}

    public function ips(string $host): array
    {
        return $this->a[$host] ?? [];
    }

    public function txt(string $host): array
    {
        return $this->txt[$host] ?? [];
    }
}

<?php

namespace Tests\Support;

use App\Services\Websites\DnsResolver;

/**
 * Answers DNS lookups in tests: host => list of IPs, TXT records or CNAME targets.
 */
class FakeDnsResolver extends DnsResolver
{
    /**
     * @param  array<string, list<string>>  $a
     * @param  array<string, list<string>>  $txt
     * @param  array<string, list<string>>  $cnames
     */
    public function __construct(public array $a = [], public array $txt = [], public array $cnames = []) {}

    public function cname(string $host): array
    {
        return $this->cnames[$host] ?? [];
    }

    public function ips(string $host): array
    {
        return $this->a[$host] ?? [];
    }

    public function txt(string $host): array
    {
        return $this->txt[$host] ?? [];
    }
}

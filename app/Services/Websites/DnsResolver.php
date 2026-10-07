<?php

namespace App\Services\Websites;

/**
 * DNS lookups for website checks, behind one class so tests can answer them.
 */
class DnsResolver
{
    /**
     * IPv4 and IPv6 addresses of a host.
     *
     * @return list<string>
     */
    public function ips(string $host): array
    {
        $ips = [];
        foreach ([DNS_A => 'ip', DNS_AAAA => 'ipv6'] as $type => $field) {
            foreach (@dns_get_record($host, $type) ?: [] as $record) {
                if (! empty($record[$field])) {
                    $ips[] = (string) $record[$field];
                }
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * TXT records of a host.
     *
     * @return list<string>
     */
    public function txt(string $host): array
    {
        return array_values(array_filter(array_map(
            fn (array $r) => isset($r['txt']) ? (string) $r['txt'] : (isset($r['entries']) ? implode('', (array) $r['entries']) : null),
            @dns_get_record($host, DNS_TXT) ?: [],
        )));
    }
}

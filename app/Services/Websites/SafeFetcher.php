<?php

namespace App\Services\Websites;

use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;

/**
 * Fetches pages from a business's website without becoming a way into our own network (SSRF).
 *
 * Every hop, redirects included: http or https on the default port only, the host must resolve to
 * public addresses only, and the connection is pinned to the address we checked (no DNS rebinding).
 * At most 3 redirects, 10 seconds and 2 MB per page.
 */
class SafeFetcher
{
    public const MAX_BYTES = 2_000_000;

    public const TIMEOUT_SECONDS = 10;

    public const MAX_REDIRECTS = 3;

    public function __construct(private readonly DnsResolver $dns) {}

    public function get(string $url, bool $headOnly = false): FetchResult
    {
        $started = microtime(true);

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $target = $this->target($url);
            if (is_string($target)) {
                return new FetchResult($url, null, error: $target);
            }
            [$host, $port, $ip] = $target;

            try {
                $response = Http::withOptions([
                    'allow_redirects' => false,
                    'stream' => ! $headOnly,
                    'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:".(str_contains($ip, ':') ? "[{$ip}]" : $ip)]],
                ])
                    ->withUserAgent('SureHelpBot/1.0 (+'.rtrim((string) config('app.url'), '/').'/website-check)')
                    ->accept('text/html,application/xhtml+xml,*/*;q=0.5')
                    ->connectTimeout(5)
                    ->timeout(self::TIMEOUT_SECONDS)
                    ->send($headOnly ? 'HEAD' : 'GET', $url);
            } catch (\Throwable $e) {
                return new FetchResult($url, null, milliseconds: $this->since($started), error: 'The site didn\'t answer ('.class_basename($e).').');
            }

            $status = $response->status();
            $location = $response->header('Location');
            if ($status >= 300 && $status < 400 && $location !== '') {
                $url = $this->absolute($location, $url);

                continue;
            }

            [$body, $truncated] = $headOnly ? ['', false] : $this->read($response->toPsrResponse());
            $headers = [];
            foreach ($response->headers() as $name => $values) {
                $headers[strtolower($name)] = implode(', ', (array) $values);
            }

            return new FetchResult($url, $status, $body, $headers, $this->since($started), truncated: $truncated);
        }

        return new FetchResult($url, null, milliseconds: $this->since($started), error: 'Too many redirects.');
    }

    /**
     * [host, port, ip] to connect to, or the reason the address isn't allowed.
     *
     * @return array{0: string, 1: int, 2: string}|string
     */
    public function target(string $url): array|string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return 'Only web addresses (http or https) can be checked.';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'Web addresses with a user name or password can\'t be checked.';
        }
        $port = $scheme === 'https' ? 443 : 80;
        if (isset($parts['port']) && (int) $parts['port'] !== $port) {
            return 'Only the standard web ports can be checked.';
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->dns->ips($host);
        if ($ips === []) {
            return "We couldn't find {$host}. Check the address.";
        }
        foreach ($ips as $ip) {
            if (! self::isPublic($ip)) {
                return 'That address points to a private network and can\'t be checked.';
            }
        }

        return [$host, $port, $ips[0]];
    }

    public static function isPublic(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            && ! str_starts_with($ip, '100.64.')                                   // carrier-grade NAT
            && ! preg_match('/^(0|169\.254|127)\./', $ip)
            && ! preg_match('/^(::ffff:|fe80:|fc|fd)/i', $ip);
    }

    public function absolute(string $href, string $base): string
    {
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $b = parse_url($base);
        $origin = ($b['scheme'] ?? 'https').'://'.($b['host'] ?? '');
        if (str_starts_with($href, '//')) {
            return ($b['scheme'] ?? 'https').':'.$href;
        }
        if (str_starts_with($href, '/')) {
            return $origin.$href;
        }
        $dir = preg_replace('#/[^/]*$#', '/', (string) ($b['path'] ?? '/')) ?: '/';

        return $origin.$dir.$href;
    }

    /**
     * @return array{0: string, 1: bool} body, truncated
     */
    private function read(ResponseInterface $response): array
    {
        $stream = $response->getBody();
        $body = '';
        while (! $stream->eof() && strlen($body) <= self::MAX_BYTES) {
            $chunk = $stream->read(65536);
            if ($chunk === '') {
                break;
            }
            $body .= $chunk;
        }

        return strlen($body) > self::MAX_BYTES ? [substr($body, 0, self::MAX_BYTES), true] : [$body, false];
    }

    private function since(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}

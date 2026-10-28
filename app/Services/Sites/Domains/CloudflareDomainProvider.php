<?php

namespace App\Services\Sites\Domains;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Cloudflare for SaaS (D101): each business domain is a "custom hostname" on our zone, and
 * Cloudflare issues and renews its certificate (HTTP validation through the CNAME the owner adds).
 */
class CloudflareDomainProvider implements DomainProvider
{
    private const API = 'https://api.cloudflare.com/client/v4';

    public function name(): string
    {
        return 'cloudflare';
    }

    public function add(string $host): ?string
    {
        $existing = $this->find($host);
        if ($existing) {
            return $existing['id'];
        }
        $response = $this->send(fn (PendingRequest $http) => $http->post($this->zone().'/custom_hostnames', [
            'hostname' => $host,
            'ssl' => ['method' => 'http', 'type' => 'dv', 'settings' => ['min_tls_version' => '1.2']],
        ]));

        return (string) $response->json('result.id');
    }

    public function status(string $host, ?string $reference): array
    {
        $result = $reference
            ? $this->send(fn (PendingRequest $http) => $http->get($this->zone().'/custom_hostnames/'.$reference))->json('result')
            : $this->find($host);
        if (! is_array($result)) {
            return ['ready' => false, 'error' => 'Cloudflare doesn\'t know this domain yet. Try removing it and adding it again.'];
        }
        $ssl = (string) ($result['ssl']['status'] ?? '');
        $errors = collect($result['ssl']['validation_errors'] ?? [])->pluck('message')->filter()->implode(' ');

        return [
            'ready' => ($result['status'] ?? '') === 'active' && $ssl === 'active',
            'error' => $errors !== '' ? 'The certificate couldn\'t be issued yet: '.$errors : null,
        ];
    }

    public function remove(string $host, ?string $reference): void
    {
        $reference ??= $this->find($host)['id'] ?? null;
        if ($reference) {
            $this->send(fn (PendingRequest $http) => $http->delete($this->zone().'/custom_hostnames/'.$reference), allowMissing: true);
        }
    }

    /** @return array<string, mixed>|null */
    private function find(string $host): ?array
    {
        $found = $this->send(fn (PendingRequest $http) => $http->get($this->zone().'/custom_hostnames', ['hostname' => $host]))->json('result.0');

        return is_array($found) ? $found : null;
    }

    private function zone(): string
    {
        $zone = (string) config('sites.custom_domains.cloudflare.zone');
        if ($zone === '' || ! config('sites.custom_domains.cloudflare.token')) {
            throw new DomainProviderFailed('Cloudflare isn\'t set up: CLOUDFLARE_API_TOKEN and CLOUDFLARE_ZONE_ID are needed.');
        }

        return self::API.'/zones/'.$zone;
    }

    /** @param  callable(PendingRequest): Response  $call */
    private function send(callable $call, bool $allowMissing = false): Response
    {
        try {
            $response = $call(Http::withToken((string) config('sites.custom_domains.cloudflare.token'))->acceptJson()->timeout(15));
        } catch (ConnectionException $e) {
            throw new DomainProviderFailed('Cloudflare couldn\'t be reached.', previous: $e);
        }
        if ($response->failed() && ! ($allowMissing && $response->status() === 404)) {
            throw new DomainProviderFailed('Cloudflare refused: '.((string) $response->json('errors.0.message') ?: 'error '.$response->status()));
        }

        return $response;
    }
}

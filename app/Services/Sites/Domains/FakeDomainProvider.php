<?php

namespace App\Services\Sites\Domains;

/**
 * For tests: remembers the domains it was given and answers with whatever `$ready` / `$error` hold.
 */
class FakeDomainProvider implements DomainProvider
{
    /** @var array<string, string> host => reference */
    public array $domains = [];

    public bool $ready = true;

    public ?string $error = null;

    public bool $fail = false;

    public function name(): string
    {
        return 'fake';
    }

    public function add(string $host): ?string
    {
        if ($this->fail) {
            throw new DomainProviderFailed('The certificate provider is down.');
        }

        return $this->domains[$host] = 'fake-'.md5($host);
    }

    public function status(string $host, ?string $reference): array
    {
        return ['ready' => $this->ready, 'error' => $this->error];
    }

    public function remove(string $host, ?string $reference): void
    {
        unset($this->domains[$host]);
    }
}

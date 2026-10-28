<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Models\User;
use App\Services\Sites\Domains\DomainProvider;
use App\Services\Sites\Domains\DomainProviderFailed;
use App\Services\Websites\DnsResolver;
use App\Support\Audit\Audit;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A business's own domain for its hosted website (D101). The owner adds one DNS record that points
 * the domain at us; that is also the proof the domain is theirs. We check it every few minutes, the
 * certificate provider issues HTTPS, and then the site answers on the domain.
 *
 * Status: pending (waiting for DNS or the certificate) → active; failed when the provider refuses it.
 * Removing the domain clears it.
 */
class SiteDomains
{
    public function __construct(
        private readonly DnsResolver $dns,
        private readonly DomainProvider $provider,
        private readonly Audit $audit,
    ) {}

    /** "https://www.example.com/path" or "Example.com." → "www.example.com". */
    public function normalize(string $input): string
    {
        $host = Str::lower(trim($input));
        $host = (string) preg_replace('#^[a-z]+://#', '', $host);
        $host = (string) Str::before(Str::before(Str::before($host, '/'), '?'), '#');
        $host = rtrim((string) preg_replace('/:\d+$/', '', $host), '.');
        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7e]/', $host)) {
            $host = (string) (idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $host);
        }

        return $host;
    }

    /** A bare domain like example.com (no www or other subdomain) can't use a CNAME at most DNS hosts. */
    public function isApex(string $host): bool
    {
        return $host === $this->registrable($host);
    }

    /** @throws ValidationException */
    public function connect(Site $site, string $input, User $by): Site
    {
        $host = $this->normalize($input);
        $own = array_filter([parse_url((string) config('app.url'), PHP_URL_HOST), config('sites.domain')]);
        $message = match (true) {
            ! preg_match('/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}$/', $host) => 'Enter a domain like www.yourbusiness.com.',
            collect($own)->contains(fn ($d) => $host === $d || str_ends_with($host, '.'.$d)) => 'That\'s one of our own addresses. Enter your business\'s domain.',
            $this->isApex($host) && ! config('sites.custom_domains.ip') => 'Use the www version, like www.'.$host.'. You can forward '.$host.' to it at your domain company.',
            Site::withoutGlobalScopes()->where('custom_domain', $host)->whereKeyNot($site->id)->exists() => 'That domain is already connected to another website.',
            ! config('sites.custom_domains.cname_target') => 'Own domains aren\'t switched on yet. Please contact SureHelp support.',
            default => null,
        };
        if ($message) {
            throw ValidationException::withMessages(['domain' => $message]);
        }

        if ($site->custom_domain && $site->custom_domain !== $host) {
            $this->release($site);
        }
        try {
            $reference = $this->provider->add($host);
        } catch (DomainProviderFailed $e) {
            report($e);
            throw ValidationException::withMessages(['domain' => 'We couldn\'t set up that domain right now. Please try again in a few minutes.']);
        }

        $site->forceFill([
            'custom_domain' => $host, 'domain_status' => 'pending', 'domain_provider_id' => $reference,
            'domain_error' => null, 'domain_checked_at' => null, 'domain_active_at' => null,
        ])->save();
        $this->audit->record('site.domain_added', $site, new: ['domain' => $host], organization: $site->organization, actor: $by, label: $host);

        return $this->check($site);
    }

    /**
     * The DNS record the owner adds at their domain company.
     *
     * @return array{type: string, name: string, value: string}|null
     */
    public function record(Site $site): ?array
    {
        if (! $site->custom_domain) {
            return null;
        }
        $host = $site->custom_domain;

        return $this->isApex($host)
            ? ['type' => 'A', 'name' => '@', 'value' => (string) config('sites.custom_domains.ip')]
            : ['type' => 'CNAME', 'name' => Str::beforeLast($host, '.'.$this->registrable($host)), 'value' => (string) config('sites.custom_domains.cname_target')];
    }

    /** Does the domain point at us yet? */
    public function pointsHere(string $host): bool
    {
        $target = strtolower((string) config('sites.custom_domains.cname_target'));
        if ($target !== '' && in_array($target, $this->dns->cname($host), true)) {
            return true;
        }
        $ours = array_filter([...($target !== '' ? $this->dns->ips($target) : []), (string) config('sites.custom_domains.ip')]);
        // CNAME flattening and A records: the domain answers with our addresses.
        $ips = $this->dns->ips($host);

        return $ips !== [] && array_intersect($ips, $ours) !== [];
    }

    /** Checks DNS and the certificate, and moves the domain along. */
    public function check(Site $site): Site
    {
        if (! $site->custom_domain) {
            return $site;
        }
        $host = $site->custom_domain;
        $updates = ['domain_checked_at' => now()];

        if (! $this->pointsHere($host)) {
            $record = $this->record($site);
            $updates += ['domain_status' => $site->domain_status === 'active' ? 'pending' : $site->domain_status,
                'domain_error' => "{$host} doesn't point to us yet. Add the {$record['type']} record shown here. Changes can take up to a few hours to show."];
        } else {
            try {
                $status = $this->provider->status($host, $site->domain_provider_id);
                $updates += $status['ready']
                    ? ['domain_status' => 'active', 'domain_error' => null, 'domain_active_at' => $site->domain_active_at ?? now()]
                    : ['domain_status' => 'pending', 'domain_error' => $status['error'] ?? 'Your domain points to us. We\'re setting up its security certificate, which usually takes a few minutes.'];
            } catch (DomainProviderFailed $e) {
                report($e);
                $updates += ['domain_error' => 'We couldn\'t check the security certificate just now. We\'ll try again shortly.'];
            }
        }

        $wasActive = $site->domain_status === 'active';
        $site->forceFill($updates)->save();
        if (! $wasActive && $site->domain_status === 'active') {
            $this->audit->record('site.domain_active', $site, new: ['domain' => $host], organization: $site->organization, label: $host);
        }

        return $site;
    }

    public function remove(Site $site, User $by): Site
    {
        $host = $site->custom_domain;
        if (! $host) {
            return $site;
        }
        $this->release($site);
        $site->forceFill(['custom_domain' => null, 'domain_status' => null, 'domain_provider_id' => null, 'domain_error' => null, 'domain_checked_at' => null, 'domain_active_at' => null])->save();
        $this->audit->record('site.domain_removed', $site, old: ['domain' => $host], organization: $site->organization, actor: $by, label: $host);

        return $site;
    }

    private function release(Site $site): void
    {
        try {
            $this->provider->remove((string) $site->custom_domain, $site->domain_provider_id);
        } catch (DomainProviderFailed $e) {
            report($e);
        }
    }

    /** "www.shop.example.co.uk" → "example.co.uk"; "www.example.com" → "example.com". */
    private function registrable(string $host): string
    {
        $labels = explode('.', $host);
        $n = count($labels);
        // Country domains sold under a second level, like .co.uk or .com.au.
        $keep = $n >= 3 && strlen($labels[$n - 1]) === 2 && in_array($labels[$n - 2], ['co', 'com', 'net', 'org', 'gov', 'edu', 'ac', 'ltd', 'plc'], true) ? 3 : 2;

        return implode('.', array_slice($labels, -$keep));
    }
}

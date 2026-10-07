<?php

namespace App\Services\Websites;

use App\Models\Website;
use App\Support\Audit\Audit;

/**
 * Proves a business owns a website (spec §41B): a meta tag on the home page, or a DNS TXT record
 * on the domain (or its parent, for "www." sites).
 */
class WebsiteVerifier
{
    public function __construct(
        private readonly SafeFetcher $fetcher,
        private readonly DnsResolver $dns,
        private readonly Audit $audit,
    ) {}

    /**
     * @return array{ok: bool, message: string}
     */
    public function verify(Website $website): array
    {
        $via = $this->viaDns($website) ? 'dns' : null;
        $pageError = null;

        if ($via === null) {
            $page = $this->fetcher->get($website->url);
            if ($page->ok()) {
                if (hash_equals($website->verification_token, (string) (new HtmlPage($page->url, $page->body))->meta(Website::META_NAME))) {
                    $via = 'meta';
                }
            } else {
                $pageError = $page->error ?? "Your home page answered with an error ({$page->status}).";
            }
        }

        if ($via === null) {
            $website->forceFill(['last_error' => $pageError])->save();

            return ['ok' => false, 'message' => $pageError
                ? "We couldn't open your site: {$pageError} You can also verify with the DNS record."
                : 'We didn\'t find the tag or the DNS record yet. Changes can take a few minutes (DNS up to a few hours) to appear.'];
        }

        $website->forceFill(['verified_at' => now(), 'verified_via' => $via, 'last_error' => null])->save();
        $this->audit->record('website.verified', $website, new: ['host' => $website->host, 'via' => $via], label: $website->host);

        return ['ok' => true, 'message' => "Verified: {$website->host} is yours."];
    }

    private function viaDns(Website $website): bool
    {
        $hosts = array_unique([$website->host, preg_replace('/^www\./', '', $website->host)]);
        foreach ($hosts as $host) {
            foreach ($this->dns->txt((string) $host) as $record) {
                if (hash_equals($website->dnsRecord(), trim($record, " \t\""))) {
                    return true;
                }
            }
        }

        return false;
    }
}

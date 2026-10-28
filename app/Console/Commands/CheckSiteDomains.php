<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Sites\SiteDomains;
use Illuminate\Console\Command;

/**
 * Businesses' own website domains (D101): every 10 minutes, domains still waiting are checked for
 * their DNS record and certificate. Working ones are rechecked once a day, so a domain that stops
 * pointing here goes back to waiting and the free address works again.
 */
class CheckSiteDomains extends Command
{
    protected $signature = 'sites:check-domains';

    protected $description = 'Check businesses\' own website domains for DNS and certificates';

    public function handle(SiteDomains $domains): int
    {
        $checked = 0;
        Site::withoutGlobalScopes()->whereNotNull('custom_domain')
            ->where(fn ($q) => $q->where('domain_status', 'pending')
                ->orWhere(fn ($q) => $q->where('domain_status', 'active')->where(fn ($q) => $q->whereNull('domain_checked_at')->orWhere('domain_checked_at', '<', now()->subDay()))))
            ->with('organization')->each(function (Site $site) use ($domains, &$checked) {
                rescue(fn () => $domains->check($site));
                $checked++;
            });
        $this->info("Checked {$checked} domain(s).");

        return self::SUCCESS;
    }
}

<?php

namespace App\Jobs;

use App\Models\Website;
use App\Services\Websites\WebsiteHealthCheck;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Runs the website health and SEO check in the background: it reads up to 10 pages and 30 links.
 */
class CheckWebsite implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 900;

    public function __construct(public readonly int $websiteId) {}

    public function uniqueId(): string
    {
        return (string) $this->websiteId;
    }

    public function handle(WebsiteHealthCheck $check): void
    {
        $website = Website::withoutGlobalScopes()->with('organization')->find($this->websiteId);
        if ($website && $website->isVerified()) {
            $check->run($website);
        }
    }
}

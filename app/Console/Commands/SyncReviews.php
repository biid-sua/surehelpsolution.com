<?php

namespace App\Console\Commands;

use App\Enums\SocialNetwork;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Services\Billing\FeatureAccess;
use App\Services\Reviews\ReviewDesk;
use Illuminate\Console\Command;

/**
 * Hourly (D83): bring in new Google reviews for every business with a connected Business Profile.
 */
class SyncReviews extends Command
{
    protected $signature = 'reviews:sync';

    protected $description = 'Read new Google reviews for each business';

    public function handle(ReviewDesk $desk, FeatureAccess $features): int
    {
        $ids = SocialAccount::withoutGlobalScopes()->where('network', SocialNetwork::GoogleBusiness->value)
            ->where('status', SocialAccount::STATUS_ACTIVE)->distinct()->pluck('organization_id');
        $new = 0;
        foreach (Organization::query()->whereIn('id', $ids)->get() as $organization) {
            if (! $organization->isServing() || ! $features->allows($organization, 'reviews')) {
                continue;
            }
            try {
                $new += $desk->sync($organization)['new'];
            } catch (\Throwable $e) {
                report($e);
                $this->warn($organization->name.': '.$e->getMessage());
            }
        }
        $this->info($new.' new reviews.');

        return self::SUCCESS;
    }
}

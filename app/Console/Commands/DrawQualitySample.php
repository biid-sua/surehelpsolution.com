<?php

namespace App\Console\Commands;

use App\Services\Quality\QualityReviews;
use Illuminate\Console\Command;

/**
 * Picks yesterday's calls for quality review at random, a few per agent (SUP-04).
 */
class DrawQualitySample extends Command
{
    protected $signature = 'quality:sample {--date= : Day to sample (Y-m-d, UTC); defaults to yesterday}';

    protected $description = 'Draw random calls per agent for quality review';

    public function handle(QualityReviews $reviews): int
    {
        $day = $this->option('date') ? now()->parse((string) $this->option('date')) : now()->subDay();
        $count = $reviews->drawSample($day);

        $this->info("Added {$count} ".str('call')->plural($count).' to the review queue.');

        return self::SUCCESS;
    }
}

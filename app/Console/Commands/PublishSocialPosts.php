<?php

namespace App\Console\Commands;

use App\Services\Social\SocialPublishing;
use Illuminate\Console\Command;

class PublishSocialPosts extends Command
{
    protected $signature = 'social:publish-due';

    protected $description = 'Queue social posts whose time has come (and retries that are due)';

    public function handle(SocialPublishing $publishing): int
    {
        $this->info($publishing->dispatchDue().' post version(s) queued.');

        return self::SUCCESS;
    }
}

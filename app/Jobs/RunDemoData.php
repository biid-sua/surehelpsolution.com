<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Demo\DemoData;
use Illuminate\Foundation\Bus\Dispatchable;
use Throwable;

/**
 * Loads or removes the demo data (D94) after the admin page has answered, so the browser doesn't
 * wait. DemoData records the outcome (loaded, failed…) for the page to show.
 */
class RunDemoData
{
    use Dispatchable;

    public function __construct(public string $action, public int $userId) {}

    public function handle(DemoData $demo): void
    {
        $user = User::find($this->userId);
        if (! $user) {
            return;
        }
        try {
            $this->action === 'load' ? $demo->load($user) : $demo->remove($user);
        } catch (Throwable) {
            // Already reported, and the state says what went wrong.
        }
    }
}

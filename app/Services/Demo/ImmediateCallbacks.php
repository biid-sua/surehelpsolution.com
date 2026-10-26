<?php

namespace App\Services\Demo;

use Illuminate\Database\DatabaseTransactionsManager;

/**
 * Used while the demo loads (D94): the load runs in one transaction so it can't half-finish, but the
 * work that normally waits for a commit (queued notifications, automation runs) must happen at its
 * moment in the demo's timeline, as it does when the seeder runs without a transaction.
 */
class ImmediateCallbacks extends DatabaseTransactionsManager
{
    public function addCallback($callback)
    {
        $callback();
    }

    public function addCallbackForRollback($callback) {}
}

<?php

namespace App\Jobs;

use App\Exceptions\AppAuthorizationLost;
use App\Models\AppConnection;
use App\Services\Apps\HousecallPro\HousecallPro;
use App\Support\DemoGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/** Brings in customers from the business's Housecall Pro (D112). */
class PullHousecallPro implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(public readonly int $connectionId) {}

    public function handle(HousecallPro $housecallPro): void
    {
        $connection = AppConnection::withoutGlobalScopes()->find($this->connectionId);
        if (! $connection || DemoGuard::isDemo($connection->organization_id) || ! HousecallPro::allowed($connection)) {
            return;
        }
        try {
            $housecallPro->pull($connection);
        } catch (AppAuthorizationLost) {
            // The connection now asks the owner to connect again.
        } catch (\Throwable $e) {
            report($e);
            $connection->forceFill(['last_error' => Str::limit($e->getMessage(), 490)])->save();
        }
    }
}

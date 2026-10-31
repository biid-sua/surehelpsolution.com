<?php

namespace App\Jobs;

use App\Exceptions\AppAuthorizationLost;
use App\Exceptions\AppRequestFailed;
use App\Models\AppConnection;
use App\Models\Appointment;
use App\Models\Customer;
use App\Services\Apps\HousecallPro\HousecallPro;
use App\Support\DemoGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends one SureHelp booking or customer to the business's Housecall Pro (D112). Housecall Pro being
 * busy or down is retried; Housecall Pro refusing the record is written in the connection's history.
 */
class SyncToHousecallPro implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public int $timeout = 90;

    /** @var list<int> */
    public array $backoff = [60, 600, 3600];

    /** @param  'appointment'|'appointment_moved'|'appointment_cancelled'|'customer'  $type */
    public function __construct(public readonly int $connectionId, public readonly string $type, public readonly int $id) {}

    public function handle(HousecallPro $housecallPro): void
    {
        $connection = AppConnection::withoutGlobalScopes()->find($this->connectionId);
        if (! $connection || ! $connection->isActive() || DemoGuard::isDemo($connection->organization_id) || ! HousecallPro::allowed($connection)) {
            return;
        }
        $record = str_starts_with($this->type, 'appointment')
            ? Appointment::withoutGlobalScopes()->where('organization_id', $connection->organization_id)->find($this->id)
            : Customer::withoutGlobalScopes()->where('organization_id', $connection->organization_id)->find($this->id);
        if (! $record) {
            return;
        }

        try {
            match (true) {
                $record instanceof Appointment && $this->type !== 'appointment' => $housecallPro->bookingChanged($connection, $record, $this->type === 'appointment_cancelled'),
                $record instanceof Appointment => $housecallPro->sendBooking($connection, $record),
                default => $housecallPro->sendCustomer($connection, $record),
            };
        } catch (AppAuthorizationLost) {
            return;   // the connection now asks the owner to connect again
        } catch (AppRequestFailed $e) {
            if ($e->appCode === 'THROTTLED') {
                throw $e;   // Housecall Pro asked us to slow down: try again later
            }
            $connection->log('failed', $record instanceof Appointment ? ($record->title ?: 'A booking') : $record->fullName(), $e->getMessage());
            $connection->forceFill(['last_error' => $e->getMessage()])->save();
        }
    }
}

<?php

namespace App\Jobs;

use App\Exceptions\AppAuthorizationLost;
use App\Exceptions\AppRequestFailed;
use App\Models\AppConnection;
use App\Models\Appointment;
use App\Models\Customer;
use App\Services\Apps\Jobber\Jobber;
use App\Support\DemoGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends one SureHelp booking or customer to the business's Jobber (D111). Jobber being busy or down
 * is retried; Jobber refusing the record is written in the connection's history instead.
 */
class SyncToJobber implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public int $timeout = 90;

    /** @var list<int> */
    public array $backoff = [60, 600, 3600];

    /** @param  'appointment'|'appointment_moved'|'appointment_cancelled'|'customer'  $type */
    public function __construct(public readonly int $connectionId, public readonly string $type, public readonly int $id) {}

    public function handle(Jobber $jobber): void
    {
        $connection = AppConnection::withoutGlobalScopes()->find($this->connectionId);
        if (! $connection || ! $connection->isActive() || DemoGuard::isDemo($connection->organization_id) || ! Jobber::allowed($connection)) {
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
                $record instanceof Appointment && $this->type !== 'appointment' => $jobber->bookingChanged($connection, $record, $this->type === 'appointment_cancelled'),
                $record instanceof Appointment => $jobber->sendBooking($connection, $record),
                default => $jobber->sendCustomer($connection, $record),
            };
        } catch (AppAuthorizationLost) {
            return;   // the connection now asks the owner to connect again
        } catch (AppRequestFailed $e) {
            if ($e->appCode === 'THROTTLED') {
                throw $e;   // Jobber asked us to slow down: try again later
            }
            $connection->log('failed', $record instanceof Appointment ? ($record->title ?: 'A booking') : $record->fullName(), $e->getMessage());
            $connection->forceFill(['last_error' => $e->getMessage()])->save();
        }
    }
}

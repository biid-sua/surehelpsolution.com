<?php

namespace App\Jobs;

use App\Exceptions\AppAuthorizationLost;
use App\Exceptions\AppRequestFailed;
use App\Models\AppConnection;
use App\Models\Customer;
use App\Models\PaymentRequest;
use App\Services\Apps\QuickBooks\QuickBooks;
use App\Support\DemoGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends one SureHelp customer or payment to the business's QuickBooks (D110). QuickBooks being down
 * is retried; QuickBooks refusing the record is written in the connection's history instead.
 */
class SyncToQuickBooks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public int $timeout = 90;

    /** @var list<int> */
    public array $backoff = [60, 600, 3600];

    /** @param  'customer'|'payment'|'refund'  $type */
    public function __construct(public readonly int $connectionId, public readonly string $type, public readonly int $id) {}

    public function handle(QuickBooks $quickBooks): void
    {
        $connection = AppConnection::withoutGlobalScopes()->find($this->connectionId);
        if (! $connection || ! $connection->isActive() || DemoGuard::isDemo($connection->organization_id) || ! QuickBooks::allowed($connection)) {
            return;
        }
        $record = in_array($this->type, ['payment', 'refund'], true)
            ? PaymentRequest::withoutGlobalScopes()->where('organization_id', $connection->organization_id)->find($this->id)
            : Customer::withoutGlobalScopes()->where('organization_id', $connection->organization_id)->find($this->id);
        if (! $record) {
            return;
        }

        try {
            match (true) {
                $this->type === 'refund' && $record instanceof PaymentRequest => $quickBooks->refundPayment($connection, $record),
                $record instanceof PaymentRequest => $quickBooks->sendPayment($connection, $record),
                default => $quickBooks->sendCustomer($connection, $record),
            };
        } catch (AppAuthorizationLost) {
            return;   // the connection now asks the owner to connect again
        } catch (AppRequestFailed $e) {
            $connection->log('failed', $record instanceof PaymentRequest ? $record->description.' ('.$record->amount().')' : $record->fullName(), $e->getMessage());
            $connection->forceFill(['last_error' => $e->getMessage()])->save();
        }
    }
}

<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Services\Integrations\Webhooks;
use App\Support\DemoGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sends one webhook delivery (D87), and schedules the next try when the endpoint doesn't answer.
 */
class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;   // retries are scheduled by hand, with growing gaps

    public function __construct(public readonly int $deliveryId) {}

    public function handle(Webhooks $webhooks): void
    {
        $delivery = WebhookDelivery::query()->with('endpoint')->find($this->deliveryId);
        if (! $delivery || $delivery->status !== 'pending' || ! $delivery->endpoint?->is_active || DemoGuard::isDemo($delivery->endpoint->organization_id)) {
            return;
        }
        if (! $webhooks->deliver($delivery) && $delivery->refresh()->status === 'pending') {
            self::dispatch($delivery->id)->delay(now()->addSeconds(Webhooks::BACKOFF[$delivery->attempts - 1] ?? 21600));
        }
    }
}

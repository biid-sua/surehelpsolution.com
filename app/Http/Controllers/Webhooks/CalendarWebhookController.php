<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\SyncCalendarConnection;
use App\Models\CalendarConnection;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

/**
 * Change notifications from Google and Microsoft (spec §17). They only say "something changed":
 * we verify the shared secret, then re-read busy times. Answering fast and never trusting content
 * means a forged call can at most trigger one harmless sync.
 */
class CalendarWebhookController extends Controller
{
    public function google(Request $request): Response
    {
        $channelId = (string) $request->header('X-Goog-Channel-ID');
        $token = (string) $request->header('X-Goog-Channel-Token');

        if ($request->header('X-Goog-Resource-State') !== 'sync' && $connection = $this->find('google', $channelId)) {
            if ($connection->push_secret && hash_equals($connection->push_secret, $token)) {
                $this->syncSoon($connection);
            }
        }

        return response('', 204);
    }

    public function microsoft(Request $request): Response
    {
        // Subscription validation handshake: echo the token as plain text within 10 seconds.
        if ($request->has('validationToken')) {
            return response((string) $request->query('validationToken'), 200, ['Content-Type' => 'text/plain']);
        }

        foreach ((array) $request->input('value', []) as $notification) {
            $connection = $this->find('microsoft', (string) ($notification['subscriptionId'] ?? ''));
            if ($connection && $connection->push_secret && hash_equals($connection->push_secret, (string) ($notification['clientState'] ?? ''))) {
                $this->syncSoon($connection);
            }
        }

        return response('', 202);
    }

    /**
     * Real-time updates: sync right after answering the provider (it wants a reply within seconds).
     * Providers often send a burst of notifications for one change, so only one sync runs per few
     * seconds; anything during that window gets a queued follow-up sync so no change is missed.
     */
    private function syncSoon(CalendarConnection $connection): void
    {
        if (Cache::add('calendar-sync-now:'.$connection->id, true, 5)) {
            // Straight to the dispatcher: the job's "unique" lock would otherwise be held for a minute and
            // swallow the notifications that follow.
            Bus::dispatchAfterResponse(new SyncCalendarConnection($connection->id));
        } else {
            SyncCalendarConnection::dispatch($connection->id)->delay(now()->addSeconds(10));
        }
    }

    private function find(string $provider, string $channelId): ?CalendarConnection
    {
        return CalendarConnection::forPushChannel($provider, $channelId);
    }
}

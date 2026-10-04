<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\SyncCalendarConnection;
use App\Models\CalendarConnection;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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
                SyncCalendarConnection::dispatch($connection->id);
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
                SyncCalendarConnection::dispatch($connection->id);
            }
        }

        return response('', 202);
    }

    private function find(string $provider, string $channelId): ?CalendarConnection
    {
        if ($channelId === '') {
            return null;
        }

        return CalendarConnection::withoutGlobalScopes()
            ->where('provider', $provider)
            ->where('status', CalendarConnection::STATUS_ACTIVE)
            ->whereNotNull('push_channels')
            ->get()
            ->first(fn (CalendarConnection $c) => collect($c->push_channels)->contains('id', $channelId));
    }
}

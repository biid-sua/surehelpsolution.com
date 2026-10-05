<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Jobs\SyncCalendarConnection;
use App\Models\CalendarConnection;
use App\Services\Calendar\CalendarManager;
use App\Services\Calendar\CalendarSync;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Connects a business calendar through OAuth (spec §18). Tokens are exchanged on the server and stored
 * encrypted; the browser only ever sees the provider's consent screen.
 */
class CalendarOAuthController extends Controller
{
    private const SESSION_KEY = 'calendar_oauth';

    public function __construct(private readonly CalendarManager $calendars) {}

    public function redirect(Request $request, CurrentOrganization $current, string $provider): RedirectResponse
    {
        $adapter = $this->calendars->provider($provider);
        abort_unless($adapter->isConfigured(), 404);

        $state = Str::random(40);
        $request->session()->put(self::SESSION_KEY, [
            'state' => $state,
            'provider' => $provider,
            'organization_id' => $current->id(),
            'user_id' => $request->user()->id,
            'at' => now()->timestamp,
            // Started from the setup wizard: come back there afterwards.
            'from_setup' => $request->query('from') === 'setup',
        ]);

        return redirect()->away($adapter->authorizationUrl($this->redirectUri($provider), $state));
    }

    public function callback(Request $request, CurrentOrganization $current, CalendarSync $sync, Audit $audit, string $provider): RedirectResponse
    {
        $pending = $request->session()->pull(self::SESSION_KEY);
        $back = ($pending['from_setup'] ?? false) ? redirect()->route('app.setup', ['step' => 'calendar']) : redirect()->route('app.business.calendars');

        // Protects against forged callbacks (CSRF): the state must be the one we issued to this user, recently.
        $valid = is_array($pending)
            && hash_equals((string) $pending['state'], (string) $request->query('state'))
            && $pending['provider'] === $provider
            && $pending['organization_id'] === $current->id()
            && $pending['user_id'] === $request->user()->id
            && now()->timestamp - $pending['at'] < 900;

        if (! $valid) {
            return $back->with('error', 'That connection attempt expired. Please try again.');
        }
        if ($request->filled('error') || ! $request->filled('code')) {
            return $back->with('error', 'The calendar wasn\'t connected: permission was not granted.');
        }

        $adapter = $this->calendars->provider($provider);

        try {
            $tokens = $adapter->exchangeCode((string) $request->query('code'), $this->redirectUri($provider));
            $email = $adapter->accountEmail($tokens->accessToken);
            $calendars = array_map(fn ($c) => $c->toArray(), $adapter->calendars($tokens->accessToken));
        } catch (\Throwable $e) {
            report($e);

            return $back->with('error', 'We couldn\'t finish connecting to '.$adapter->label().'. Please try again.');
        }

        $primary = collect($calendars)->firstWhere('primary', true) ?? ($calendars[0] ?? null);
        $existing = CalendarConnection::query()->forOrganization($current->get())->where('provider', $provider)->first();

        $connection = CalendarConnection::updateOrCreate(
            ['organization_id' => $current->id(), 'provider' => $provider],
            [
                'connected_by_user_id' => $request->user()->id,
                'account_email' => $email,
                'access_token' => $tokens->accessToken,
                'refresh_token' => $tokens->refreshToken ?? $existing?->refresh_token,
                'token_expires_at' => $tokens->expiresAt,
                'scopes' => $tokens->scope,
                'calendars' => $calendars,
                // A reconnect keeps the business's earlier choices when those calendars still exist.
                'write_calendar_id' => $existing && collect($calendars)->contains('id', $existing->write_calendar_id) ? $existing->write_calendar_id : ($primary['can_write'] ?? false ? $primary['id'] : null),
                'busy_calendar_ids' => $existing?->busy_calendar_ids ?: (isset($primary['id']) ? [$primary['id']] : []),
                'status' => CalendarConnection::STATUS_ACTIVE,
                'last_error' => null,
            ],
        );

        $audit->record($existing ? 'calendar.reconnected' : 'calendar.connected', $connection,
            new: ['provider' => $provider, 'account' => $email], organization: $current->get(), label: $adapter->label());

        SyncCalendarConnection::dispatch($connection->id);
        $sync->ensurePush($connection);

        return $back->with('success', $adapter->label().' connected'.($email ? ' ('.$email.')' : '').'.');
    }

    private function redirectUri(string $provider): string
    {
        return route('app.integrations.calendar.callback', $provider);
    }
}

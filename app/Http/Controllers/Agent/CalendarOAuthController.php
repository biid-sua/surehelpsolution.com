<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Jobs\SyncAgentCalendar;
use App\Models\AgentCalendarConnection;
use App\Services\Calendar\CalendarManager;
use App\Support\Audit\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Connects an agent's own Google or Microsoft calendar (D54). Same protections as a business
 * connection: a one-time state bound to this user, tokens exchanged and stored (encrypted) on
 * the server only.
 */
class CalendarOAuthController extends Controller
{
    private const SESSION_KEY = 'agent_calendar_oauth';

    public function __construct(private readonly CalendarManager $calendars) {}

    public function redirect(Request $request, string $provider): RedirectResponse
    {
        abort_unless(array_key_exists($provider, CalendarManager::PROVIDERS), 404);
        $adapter = $this->calendars->provider($provider);
        abort_unless($adapter->isConfigured(), 404);

        $state = Str::random(40);
        $request->session()->put(self::SESSION_KEY, ['state' => $state, 'provider' => $provider, 'user_id' => $request->user()->id, 'at' => now()->timestamp]);

        return redirect()->away($adapter->authorizationUrl($this->redirectUri($provider), $state));
    }

    public function callback(Request $request, Audit $audit, string $provider): RedirectResponse
    {
        abort_unless(array_key_exists($provider, CalendarManager::PROVIDERS), 404);
        $pending = $request->session()->pull(self::SESSION_KEY);
        $back = redirect()->route('agent.calendar');

        $valid = is_array($pending)
            && hash_equals((string) $pending['state'], (string) $request->query('state'))
            && $pending['provider'] === $provider
            && $pending['user_id'] === $request->user()->id
            && now()->timestamp - $pending['at'] < 900;
        if (! $valid) {
            return $back->with('error', 'That connection attempt expired. Please try again.');
        }
        if ($request->filled('error') || ! $request->filled('code')) {
            return $back->with('error', 'Your calendar wasn\'t connected: permission was not granted.');
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

        $user = $request->user();
        $primary = collect($calendars)->firstWhere('primary', true) ?? ($calendars[0] ?? null);
        $existing = AgentCalendarConnection::query()->where('user_id', $user->id)->where('provider', $provider)->first();
        $keep = fn (?string $id) => $id && collect($calendars)->contains('id', $id);

        $connection = AgentCalendarConnection::updateOrCreate(['user_id' => $user->id, 'provider' => $provider], [
            'account_email' => $email,
            'access_token' => $tokens->accessToken,
            'refresh_token' => $tokens->refreshToken ?? $existing?->refresh_token,
            'token_expires_at' => $tokens->expiresAt,
            'scopes' => $tokens->scope,
            'calendars' => $calendars,
            // A reconnect keeps earlier choices when those calendars still exist.
            'write_calendar_id' => $keep($existing?->write_calendar_id) ? $existing->write_calendar_id : (($primary['can_write'] ?? false) ? $primary['id'] : null),
            'busy_calendar_ids' => $existing?->busy_calendar_ids ?: (isset($primary['id']) ? [$primary['id']] : []),
            'status' => AgentCalendarConnection::STATUS_ACTIVE,
            'last_error' => null,
        ]);
        $audit->record($existing ? 'agent_calendar.reconnected' : 'agent_calendar.connected', $connection,
            new: ['provider' => $provider, 'account' => $email], actor: $user, label: $adapter->label());
        SyncAgentCalendar::dispatch($connection->id);

        return $back->with('success', $adapter->label().' connected'.($email ? ' ('.$email.')' : '').'. Your shifts will appear in it shortly.');
    }

    private function redirectUri(string $provider): string
    {
        return route('agent.calendar.callback', $provider);
    }
}

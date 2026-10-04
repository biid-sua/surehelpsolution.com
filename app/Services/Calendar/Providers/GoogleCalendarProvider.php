<?php

namespace App\Services\Calendar\Providers;

use App\Exceptions\CalendarAuthorizationLost;
use App\Exceptions\CalendarEventChanged;
use App\Services\Calendar\Contracts\CalendarProvider;
use App\Services\Calendar\Data\BusyInterval;
use App\Services\Calendar\Data\EventPayload;
use App\Services\Calendar\Data\EventRef;
use App\Services\Calendar\Data\ExternalCalendar;
use App\Services\Calendar\Data\OAuthTokens;
use App\Services\Calendar\Data\PushChannel;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Google Calendar API v3 over plain HTTPS (no SDK: smaller deploys, fully fakeable in tests).
 */
class GoogleCalendarProvider implements CalendarProvider
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const API = 'https://www.googleapis.com/calendar/v3';

    public function key(): string
    {
        return 'google';
    }

    public function label(): string
    {
        return 'Google Calendar';
    }

    public function isConfigured(): bool
    {
        return filled(config('calendar.providers.google.client_id')) && filled(config('calendar.providers.google.client_secret'));
    }

    public function authorizationUrl(string $redirectUri, string $state): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => config('calendar.providers.google.client_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', config('calendar.providers.google.scopes')),
            'access_type' => 'offline',     // we need a refresh token to sync while nobody is logged in
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    public function exchangeCode(string $code, string $redirectUri): OAuthTokens
    {
        return $this->tokens(Http::asForm()->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => config('calendar.providers.google.client_id'),
            'client_secret' => config('calendar.providers.google.client_secret'),
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]));
    }

    public function refresh(string $refreshToken): OAuthTokens
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'refresh_token' => $refreshToken,
            'client_id' => config('calendar.providers.google.client_id'),
            'client_secret' => config('calendar.providers.google.client_secret'),
            'grant_type' => 'refresh_token',
        ]);

        if (in_array($response->json('error'), ['invalid_grant', 'unauthorized_client'], true) || $response->status() === 401) {
            throw new CalendarAuthorizationLost('Google access was revoked or expired.');
        }

        $tokens = $this->tokens($response);

        // Google doesn't send the refresh token again on refresh: keep the one we have.
        return new OAuthTokens($tokens->accessToken, $tokens->refreshToken ?? $refreshToken, $tokens->expiresAt, $tokens->scope);
    }

    public function revoke(string $token): void
    {
        Http::asForm()->post('https://oauth2.googleapis.com/revoke', ['token' => $token]);
    }

    public function accountEmail(string $accessToken): ?string
    {
        return $this->api($accessToken)->get('https://openidconnect.googleapis.com/v1/userinfo')->json('email');
    }

    public function calendars(string $accessToken): array
    {
        $items = $this->ok($this->api($accessToken)->get(self::API.'/users/me/calendarList', ['minAccessRole' => 'reader']))->json('items', []);

        return array_values(array_map(fn (array $c) => new ExternalCalendar(
            (string) $c['id'],
            (string) ($c['summaryOverride'] ?? $c['summary'] ?? $c['id']),
            (bool) ($c['primary'] ?? false),
            in_array($c['accessRole'] ?? '', ['owner', 'writer'], true),
        ), $items));
    }

    public function busy(string $accessToken, string $calendarId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $busy = [];
        $pageToken = null;

        do {
            $json = $this->ok($this->api($accessToken)->get(self::API.'/calendars/'.rawurlencode($calendarId).'/events', array_filter([
                'timeMin' => $from->utc()->toRfc3339String(),
                'timeMax' => $to->utc()->toRfc3339String(),
                'singleEvents' => 'true',
                'showDeleted' => 'false',
                'maxResults' => 2500,
                'fields' => 'nextPageToken,items(id,status,transparency,start,end)',
                'pageToken' => $pageToken,
            ])))->json();

            foreach ($json['items'] ?? [] as $event) {
                if (($event['status'] ?? '') === 'cancelled' || ($event['transparency'] ?? '') === 'transparent') {
                    continue;   // cancelled, or marked "free"
                }
                $allDay = isset($event['start']['date']);
                $start = $allDay ? CarbonImmutable::parse($event['start']['date'], 'UTC') : CarbonImmutable::parse($event['start']['dateTime']);
                $end = $allDay ? CarbonImmutable::parse($event['end']['date'], 'UTC') : CarbonImmutable::parse($event['end']['dateTime']);
                $busy[] = new BusyInterval((string) $event['id'], $start->utc(), $end->utc(), $allDay);
            }

            $pageToken = $json['nextPageToken'] ?? null;
        } while ($pageToken);

        return $busy;
    }

    public function createEvent(string $accessToken, string $calendarId, EventPayload $event): EventRef
    {
        $json = $this->ok($this->api($accessToken)->post(self::API.'/calendars/'.rawurlencode($calendarId).'/events', $this->body($event)))->json();

        return new EventRef((string) $json['id'], $json['etag'] ?? null);
    }

    public function updateEvent(string $accessToken, string $calendarId, EventRef $ref, EventPayload $event): EventRef
    {
        $request = $this->api($accessToken);
        if ($ref->etag) {
            $request = $request->withHeaders(['If-Match' => $ref->etag]);
        }

        $response = $request->patch(self::API.'/calendars/'.rawurlencode($calendarId).'/events/'.rawurlencode($ref->id), $this->body($event));
        if ($response->status() === 412) {
            throw new CalendarEventChanged('The event was changed in Google Calendar.');
        }
        $json = $this->ok($response)->json();

        return new EventRef((string) $json['id'], $json['etag'] ?? null);
    }

    public function deleteEvent(string $accessToken, string $calendarId, EventRef $ref): void
    {
        $response = $this->api($accessToken)->delete(self::API.'/calendars/'.rawurlencode($calendarId).'/events/'.rawurlencode($ref->id));
        if (! in_array($response->status(), [404, 410], true)) {   // already gone is fine
            $this->ok($response);
        }
    }

    public function watch(string $accessToken, string $calendarId, string $notificationUrl, string $secret): ?PushChannel
    {
        $json = $this->ok($this->api($accessToken)->post(self::API.'/calendars/'.rawurlencode($calendarId).'/events/watch', [
            'id' => (string) Str::uuid(),
            'type' => 'web_hook',
            'address' => $notificationUrl,
            'token' => $secret,
            'params' => ['ttl' => (string) (7 * 24 * 3600)],
        ]))->json();

        return new PushChannel($calendarId, (string) $json['id'], $json['resourceId'] ?? null,
            CarbonImmutable::createFromTimestampMs((int) ($json['expiration'] ?? now()->addDays(7)->getTimestampMs())));
    }

    public function stopWatch(string $accessToken, PushChannel $channel): void
    {
        $this->api($accessToken)->post(self::API.'/channels/stop', ['id' => $channel->id, 'resourceId' => $channel->resourceId]);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(EventPayload $event): array
    {
        return array_filter([
            'summary' => $event->title,
            'description' => $event->description,
            'location' => $event->location,
            'start' => ['dateTime' => $event->start->setTimezone($event->timezone)->toRfc3339String(), 'timeZone' => $event->timezone],
            'end' => ['dateTime' => $event->end->setTimezone($event->timezone)->toRfc3339String(), 'timeZone' => $event->timezone],
            'extendedProperties' => $event->appointmentId ? ['private' => ['surehelpAppointment' => $event->appointmentId]] : null,
            'source' => ['title' => 'SureHelp', 'url' => rtrim((string) config('app.url'), '/').'/app/appointments'],
        ], fn ($v) => $v !== null);
    }

    private function api(string $accessToken): PendingRequest
    {
        return Http::withToken($accessToken)->acceptJson()->timeout(20)->retry(2, 300, fn ($e) => $e instanceof ConnectionException, throw: false);
    }

    /**
     * @throws CalendarAuthorizationLost
     * @throws RequestException
     */
    private function ok(Response $response): Response
    {
        if ($response->status() === 401) {
            throw new CalendarAuthorizationLost('Google rejected our access token.');
        }

        return $response->throw();
    }

    private function tokens(Response $response): OAuthTokens
    {
        $json = $this->ok($response)->json();

        return new OAuthTokens(
            (string) $json['access_token'],
            $json['refresh_token'] ?? null,
            CarbonImmutable::now()->addSeconds((int) ($json['expires_in'] ?? 3600)),
            $json['scope'] ?? null,
        );
    }
}

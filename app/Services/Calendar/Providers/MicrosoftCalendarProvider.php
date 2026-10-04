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
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Microsoft 365 / Outlook calendars through Microsoft Graph v1.0 over plain HTTPS.
 */
class MicrosoftCalendarProvider implements CalendarProvider
{
    private const GRAPH = 'https://graph.microsoft.com/v1.0';

    /** Graph allows at most 4230 minutes for calendar subscriptions. */
    private const SUBSCRIPTION_MINUTES = 4200;

    public function key(): string
    {
        return 'microsoft';
    }

    public function label(): string
    {
        return 'Microsoft Outlook / 365';
    }

    public function isConfigured(): bool
    {
        return filled(config('calendar.providers.microsoft.client_id')) && filled(config('calendar.providers.microsoft.client_secret'));
    }

    private function loginUrl(string $path): string
    {
        return 'https://login.microsoftonline.com/'.config('calendar.providers.microsoft.tenant', 'common').'/oauth2/v2.0/'.$path;
    }

    public function authorizationUrl(string $redirectUri, string $state): string
    {
        return $this->loginUrl('authorize').'?'.http_build_query([
            'client_id' => config('calendar.providers.microsoft.client_id'),
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'response_mode' => 'query',
            'scope' => implode(' ', config('calendar.providers.microsoft.scopes')),
            'prompt' => 'select_account',
            'state' => $state,
        ]);
    }

    public function exchangeCode(string $code, string $redirectUri): OAuthTokens
    {
        return $this->tokens(Http::asForm()->post($this->loginUrl('token'), [
            'client_id' => config('calendar.providers.microsoft.client_id'),
            'client_secret' => config('calendar.providers.microsoft.client_secret'),
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
            'scope' => implode(' ', config('calendar.providers.microsoft.scopes')),
        ]));
    }

    public function refresh(string $refreshToken): OAuthTokens
    {
        $response = Http::asForm()->post($this->loginUrl('token'), [
            'client_id' => config('calendar.providers.microsoft.client_id'),
            'client_secret' => config('calendar.providers.microsoft.client_secret'),
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
            'scope' => implode(' ', config('calendar.providers.microsoft.scopes')),
        ]);

        if (in_array($response->json('error'), ['invalid_grant', 'interaction_required', 'unauthorized_client'], true)) {
            throw new CalendarAuthorizationLost('Microsoft access was revoked or expired.');
        }

        $tokens = $this->tokens($response);

        return new OAuthTokens($tokens->accessToken, $tokens->refreshToken ?? $refreshToken, $tokens->expiresAt, $tokens->scope);
    }

    public function revoke(string $token): void
    {
        // Graph has no token revocation endpoint for delegated apps; the business can remove access in their account.
    }

    public function accountEmail(string $accessToken): ?string
    {
        $me = $this->ok($this->api($accessToken)->get(self::GRAPH.'/me', ['$select' => 'mail,userPrincipalName']))->json();

        return $me['mail'] ?? $me['userPrincipalName'] ?? null;
    }

    public function calendars(string $accessToken): array
    {
        $items = $this->ok($this->api($accessToken)->get(self::GRAPH.'/me/calendars', ['$select' => 'id,name,isDefaultCalendar,canEdit', '$top' => 100]))->json('value', []);

        return array_values(array_map(fn (array $c) => new ExternalCalendar(
            (string) $c['id'], (string) ($c['name'] ?? 'Calendar'), (bool) ($c['isDefaultCalendar'] ?? false), (bool) ($c['canEdit'] ?? true),
        ), $items));
    }

    public function busy(string $accessToken, string $calendarId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $busy = [];
        $url = self::GRAPH.'/me/calendars/'.rawurlencode($calendarId).'/calendarView?'.http_build_query([
            'startDateTime' => $from->utc()->format('Y-m-d\TH:i:s\Z'),
            'endDateTime' => $to->utc()->format('Y-m-d\TH:i:s\Z'),
            '$select' => 'id,showAs,isCancelled,isAllDay,start,end',
            '$top' => 500,
        ]);

        while ($url) {
            $json = $this->ok($this->api($accessToken)->withHeaders(['Prefer' => 'outlook.timezone="UTC"'])->get($url))->json();

            foreach ($json['value'] ?? [] as $event) {
                if (($event['isCancelled'] ?? false) || in_array($event['showAs'] ?? 'busy', ['free', 'workingElsewhere'], true)) {
                    continue;
                }
                $busy[] = new BusyInterval(
                    (string) $event['id'],
                    CarbonImmutable::parse($event['start']['dateTime'], 'UTC'),
                    CarbonImmutable::parse($event['end']['dateTime'], 'UTC'),
                    (bool) ($event['isAllDay'] ?? false),
                );
            }

            $url = $json['@odata.nextLink'] ?? null;
        }

        return $busy;
    }

    public function createEvent(string $accessToken, string $calendarId, EventPayload $event): EventRef
    {
        $json = $this->ok($this->api($accessToken)->post(self::GRAPH.'/me/calendars/'.rawurlencode($calendarId).'/events', $this->body($event)))->json();

        return new EventRef((string) $json['id'], $json['@odata.etag'] ?? null);
    }

    public function updateEvent(string $accessToken, string $calendarId, EventRef $ref, EventPayload $event): EventRef
    {
        $request = $this->api($accessToken);
        if ($ref->etag) {
            $request = $request->withHeaders(['If-Match' => $ref->etag]);
        }

        $response = $request->patch(self::GRAPH.'/me/events/'.rawurlencode($ref->id), $this->body($event));
        if ($response->status() === 412) {
            throw new CalendarEventChanged('The event was changed in Outlook.');
        }
        $json = $this->ok($response)->json();

        return new EventRef((string) ($json['id'] ?? $ref->id), $json['@odata.etag'] ?? null);
    }

    public function deleteEvent(string $accessToken, string $calendarId, EventRef $ref): void
    {
        $response = $this->api($accessToken)->delete(self::GRAPH.'/me/events/'.rawurlencode($ref->id));
        if ($response->status() !== 404) {
            $this->ok($response);
        }
    }

    public function watch(string $accessToken, string $calendarId, string $notificationUrl, string $secret): ?PushChannel
    {
        $json = $this->ok($this->api($accessToken)->post(self::GRAPH.'/subscriptions', [
            'changeType' => 'created,updated,deleted',
            'notificationUrl' => $notificationUrl,
            'resource' => '/me/calendars/'.$calendarId.'/events',
            'expirationDateTime' => now()->utc()->addMinutes(self::SUBSCRIPTION_MINUTES)->format('Y-m-d\TH:i:s\Z'),
            'clientState' => $secret,
        ]))->json();

        return new PushChannel($calendarId, (string) $json['id'], null, CarbonImmutable::parse($json['expirationDateTime']));
    }

    public function stopWatch(string $accessToken, PushChannel $channel): void
    {
        $this->api($accessToken)->delete(self::GRAPH.'/subscriptions/'.rawurlencode($channel->id));
    }

    /**
     * @return array<string, mixed>
     */
    private function body(EventPayload $event): array
    {
        return array_filter([
            'subject' => $event->title,
            'body' => $event->description ? ['contentType' => 'text', 'content' => $event->description] : null,
            'start' => ['dateTime' => $event->start->utc()->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'],
            'end' => ['dateTime' => $event->end->utc()->format('Y-m-d\TH:i:s'), 'timeZone' => 'UTC'],
            'location' => $event->location ? ['displayName' => $event->location] : null,
            'showAs' => 'busy',
        ], fn ($v) => $v !== null);
    }

    private function api(string $accessToken): PendingRequest
    {
        return Http::withToken($accessToken)->acceptJson()->timeout(20)->retry(2, 300, fn ($e) => $e instanceof ConnectionException, throw: false);
    }

    private function ok(Response $response): Response
    {
        if ($response->status() === 401) {
            throw new CalendarAuthorizationLost('Microsoft rejected our access token.');
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

<?php

namespace App\Services\Seo;

use App\Exceptions\CalendarAuthorizationLost;
use App\Models\Organization;
use App\Models\SearchConsoleConnection;
use App\Models\SeoDailyMetric;
use App\Models\User;
use App\Services\Calendar\Providers\GoogleCalendarProvider;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Google Search Console, read-only (D57): how often the business's site shows in Google, how often
 * people click, and its average position, by day, plus the searches it's found for. Uses the same
 * Google app as calendar sync with the `webmasters.readonly` scope. Search Console data is ~2–3 days behind.
 */
class SearchConsole
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const API = 'https://www.googleapis.com/webmasters/v3';

    public function __construct(private readonly GoogleCalendarProvider $google) {}

    public function isConfigured(): bool
    {
        return $this->google->isConfigured();
    }

    public function redirectUri(): string
    {
        return route('app.seo.search-console.callback');
    }

    public function authorizationUrl(string $state): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => config('calendar.providers.google.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', config('seo.search_console.scopes')),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    public function connect(Organization $organization, string $code, User $by): SearchConsoleConnection
    {
        $tokens = $this->google->exchangeCode($code, $this->redirectUri());
        $sites = $this->sites($tokens->accessToken);

        $connection = SearchConsoleConnection::withoutGlobalScopes()->updateOrCreate(['organization_id' => $organization->id], [
            'connected_by_user_id' => $by->id,
            'account_email' => $this->google->accountEmail($tokens->accessToken),
            'access_token' => $tokens->accessToken,
            'refresh_token' => $tokens->refreshToken,
            'token_expires_at' => $tokens->expiresAt,
            'sites' => $sites,
            'site_url' => $this->guessSite($organization, $sites),
            'status' => SearchConsoleConnection::STATUS_ACTIVE,
            'last_error' => null,
        ]);

        return $connection;
    }

    /** @return list<array{url: string, permission: string}> */
    public function sites(string $accessToken): array
    {
        $entries = $this->ok(Http::withToken($accessToken)->acceptJson()->timeout(20)->get(self::API.'/sites'))->json('siteEntry', []);

        return array_values(array_map(fn (array $s) => ['url' => (string) $s['siteUrl'], 'permission' => (string) ($s['permissionLevel'] ?? '')],
            array_filter($entries, fn (array $s) => ($s['permissionLevel'] ?? '') !== 'siteUnverifiedUser')));
    }

    /**
     * Fetch daily numbers (and the top searches of the last 28 days). On first connect, the whole
     * history Search Console keeps; afterwards the last couple of weeks, which Google keeps revising.
     *
     * @return int days stored
     */
    public function pull(SearchConsoleConnection $connection): int
    {
        if (! $connection->isActive() || ! $connection->site_url) {
            return 0;
        }

        try {
            $token = $this->accessToken($connection);
            $end = CarbonImmutable::today()->subDays(2);
            $start = $connection->last_synced_at ? $end->subDays(14) : $end->subDays((int) config('seo.search_console.days', 480));

            $rows = $this->query($token, $connection->site_url, $start, $end, ['date'], 25000);
            DB::transaction(function () use ($connection, $rows) {
                foreach ($rows as $row) {
                    SeoDailyMetric::withoutGlobalScopes()->updateOrCreate(
                        ['organization_id' => $connection->organization_id, 'date' => $row['keys'][0]],
                        ['clicks' => (int) $row['clicks'], 'impressions' => (int) $row['impressions'], 'position' => round((float) $row['position'], 2)],
                    );
                }
            });

            $queries = $this->query($token, $connection->site_url, $end->subDays(27), $end, ['query'], 20);
            $connection->forceFill([
                'top_queries' => array_map(fn (array $r) => [
                    'query' => (string) $r['keys'][0], 'clicks' => (int) $r['clicks'], 'impressions' => (int) $r['impressions'], 'position' => round((float) $r['position'], 1),
                ], $queries),
                'last_synced_at' => now(),
                'last_error' => null,
            ])->save();

            return count($rows);
        } catch (CalendarAuthorizationLost) {
            $connection->forceFill(['status' => SearchConsoleConnection::STATUS_NEEDS_REAUTH, 'last_error' => 'Google access was removed or expired.'])->save();

            return 0;
        } catch (\Throwable $e) {
            report($e);
            $connection->forceFill(['last_error' => Str::limit($e->getMessage(), 500)])->save();

            return 0;
        }
    }

    private function accessToken(SearchConsoleConnection $connection): string
    {
        if ($connection->token_expires_at && $connection->token_expires_at->greaterThan(now()->addMinute())) {
            return $connection->access_token;
        }
        if (! $connection->refresh_token) {
            throw new CalendarAuthorizationLost('No refresh token.');
        }
        $tokens = $this->google->refresh($connection->refresh_token);
        $connection->forceFill(['access_token' => $tokens->accessToken, 'refresh_token' => $tokens->refreshToken, 'token_expires_at' => $tokens->expiresAt])->save();

        return $tokens->accessToken;
    }

    /**
     * @param  list<string>  $dimensions
     * @return list<array{keys: list<string>, clicks: float, impressions: float, position: float}>
     */
    private function query(string $token, string $site, CarbonImmutable $start, CarbonImmutable $end, array $dimensions, int $limit): array
    {
        return $this->ok(Http::withToken($token)->acceptJson()->timeout(30)->post(self::API.'/sites/'.rawurlencode($site).'/searchAnalytics/query', [
            'startDate' => $start->toDateString(),
            'endDate' => $end->toDateString(),
            'dimensions' => $dimensions,
            'rowLimit' => $limit,
        ]))->json('rows', []);
    }

    /** The Search Console property that matches the business's website, if any. */
    private function guessSite(Organization $organization, array $sites): ?string
    {
        $hosts = DB::table('websites')->where('organization_id', $organization->id)->pluck('host')
            ->map(fn ($h) => preg_replace('/^www\./', '', (string) $h))->all();
        foreach ($sites as $site) {
            $host = preg_replace('/^www\./', '', (string) (str_starts_with($site['url'], 'sc-domain:') ? substr($site['url'], 10) : parse_url($site['url'], PHP_URL_HOST)));
            if (in_array($host, $hosts, true)) {
                return $site['url'];
            }
        }

        return count($sites) === 1 ? $sites[0]['url'] : null;
    }

    private function ok(Response $response): Response
    {
        if ($response->status() === 401) {
            throw new CalendarAuthorizationLost('Google access was removed.');
        }

        return $response->throw();
    }
}

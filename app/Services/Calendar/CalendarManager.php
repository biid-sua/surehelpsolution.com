<?php

namespace App\Services\Calendar;

use App\Actions\Notifications\NotifyOrganization;
use App\Exceptions\CalendarAuthorizationLost;
use App\Models\AgentCalendarConnection;
use App\Models\CalendarConnection;
use App\Notifications\AgentCalendarDisconnected;
use App\Notifications\CalendarDisconnected;
use App\Services\Calendar\Contracts\CalendarProvider;
use App\Services\Calendar\Providers\GoogleCalendarProvider;
use App\Services\Calendar\Providers\MicrosoftCalendarProvider;
use App\Support\Audit\Audit;
use InvalidArgumentException;

/**
 * Picks the provider adapter and keeps access tokens fresh. When access is lost, the connection is
 * flagged once and the business is told to reconnect (CAL-06).
 */
class CalendarManager
{
    public const PROVIDERS = ['google' => GoogleCalendarProvider::class, 'microsoft' => MicrosoftCalendarProvider::class];

    public function __construct(
        private readonly NotifyOrganization $notify,
        private readonly Audit $audit,
    ) {}

    public function provider(string $key): CalendarProvider
    {
        $class = self::PROVIDERS[$key] ?? throw new InvalidArgumentException("Unknown calendar provider [{$key}].");

        return app($class);
    }

    /**
     * @return array<string, CalendarProvider>
     */
    public function providers(): array
    {
        return array_map(fn (string $class) => app($class), self::PROVIDERS);
    }

    /**
     * A valid access token, refreshed when it is about to expire.
     *
     * @throws CalendarAuthorizationLost
     */
    public function accessToken(CalendarConnection|AgentCalendarConnection $connection): string
    {
        if ($connection->status === CalendarConnection::STATUS_NEEDS_REAUTH) {
            throw new CalendarAuthorizationLost('This calendar needs to be reconnected.');
        }

        if ($connection->token_expires_at && $connection->token_expires_at->greaterThan(now()->addMinute())) {
            return $connection->access_token;
        }

        if (! $connection->refresh_token) {
            $this->lost($connection, 'No refresh token was granted.');

            throw new CalendarAuthorizationLost('No refresh token.');
        }

        try {
            $tokens = $this->provider($connection->provider)->refresh($connection->refresh_token);
        } catch (CalendarAuthorizationLost $e) {
            $this->lost($connection, $e->getMessage());

            throw $e;
        }

        $connection->forceFill([
            'access_token' => $tokens->accessToken,
            'refresh_token' => $tokens->refreshToken,
            'token_expires_at' => $tokens->expiresAt,
        ])->save();

        return $tokens->accessToken;
    }

    /**
     * Access was revoked or expired: flag it and tell the business once.
     */
    public function lost(CalendarConnection|AgentCalendarConnection $connection, string $reason): void
    {
        if ($connection->status === CalendarConnection::STATUS_NEEDS_REAUTH) {
            return;
        }

        $connection->forceFill(['status' => CalendarConnection::STATUS_NEEDS_REAUTH, 'last_error' => $reason])->save();
        if ($connection instanceof AgentCalendarConnection) {
            // An agent's own calendar (D54): only they are told.
            $this->audit->record('agent_calendar.access_lost', $connection, new: ['provider' => $connection->provider, 'reason' => $reason],
                actor: $connection->user, label: $this->provider($connection->provider)->label());
            $connection->user?->notify(new AgentCalendarDisconnected($connection));

            return;
        }
        $this->audit->record('calendar.access_lost', $connection, new: ['provider' => $connection->provider, 'reason' => $reason],
            organization: $connection->organization, label: $this->provider($connection->provider)->label());

        if ($connection->organization) {
            $this->notify->handle($connection->organization, new CalendarDisconnected($connection), 'integrations.manage');
        }
    }
}

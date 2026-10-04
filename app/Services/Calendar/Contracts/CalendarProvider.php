<?php

namespace App\Services\Calendar\Contracts;

use App\Exceptions\CalendarAuthorizationLost;
use App\Exceptions\CalendarEventChanged;
use App\Services\Calendar\Data\BusyInterval;
use App\Services\Calendar\Data\EventPayload;
use App\Services\Calendar\Data\EventRef;
use App\Services\Calendar\Data\ExternalCalendar;
use App\Services\Calendar\Data\OAuthTokens;
use App\Services\Calendar\Data\PushChannel;
use Carbon\CarbonImmutable;

/**
 * One external calendar service (spec §17). Everything provider-specific stays behind this interface;
 * the rest of the app talks to CalendarConnection / CalendarSync only.
 */
interface CalendarProvider
{
    public function key(): string;

    public function label(): string;

    /** SureHelp has registered an OAuth app for this provider. */
    public function isConfigured(): bool;

    public function authorizationUrl(string $redirectUri, string $state): string;

    public function exchangeCode(string $code, string $redirectUri): OAuthTokens;

    /** @throws CalendarAuthorizationLost */
    public function refresh(string $refreshToken): OAuthTokens;

    public function revoke(string $token): void;

    public function accountEmail(string $accessToken): ?string;

    /** @return list<ExternalCalendar> */
    public function calendars(string $accessToken): array;

    /**
     * Busy periods (events that block time), excluding cancelled and "free" events.
     *
     * @return list<BusyInterval>
     */
    public function busy(string $accessToken, string $calendarId, CarbonImmutable $from, CarbonImmutable $to): array;

    public function createEvent(string $accessToken, string $calendarId, EventPayload $event): EventRef;

    /** @throws CalendarEventChanged when it was edited externally since $ref->etag */
    public function updateEvent(string $accessToken, string $calendarId, EventRef $ref, EventPayload $event): EventRef;

    public function deleteEvent(string $accessToken, string $calendarId, EventRef $ref): void;

    public function watch(string $accessToken, string $calendarId, string $notificationUrl, string $secret): ?PushChannel;

    public function stopWatch(string $accessToken, PushChannel $channel): void;
}

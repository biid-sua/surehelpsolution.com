<?php

namespace App\Support\Calendar;

use App\Models\CalendarConnection;
use App\Models\Organization;
use App\Services\Calendar\CalendarManager;

/**
 * Where an entry on the business calendar comes from. Every event in the calendar feed is
 * tagged with one of these, so people can see and filter what is ours and what is theirs.
 */
class CalendarSources
{
    public const BOOKINGS = 'surehelp';

    public const VISITS = 'visits';

    /** Short tags shown on events; full names come from the provider adapters. */
    public const TAGS = ['google' => 'Google', 'microsoft' => 'Outlook'];

    public function __construct(private readonly CalendarManager $calendars) {}

    /** @return list<string> */
    public static function keys(): array
    {
        return [self::BOOKINGS, self::VISITS, ...array_keys(CalendarManager::PROVIDERS)];
    }

    /**
     * The filter chips for a business: our bookings and visits, plus each provider it can connect,
     * with that connection's state.
     *
     * @return list<array{key: string, label: string, tag: string, kind: string, connected: bool, status: ?string, account: ?string, synced: ?string}>
     */
    public function for(Organization $organization): array
    {
        $connections = CalendarConnection::query()->forOrganization($organization)->get()->keyBy('provider');

        $sources = [
            ['key' => self::BOOKINGS, 'label' => 'SureHelp bookings', 'tag' => 'SureHelp', 'kind' => 'internal', 'connected' => true, 'status' => null, 'account' => null, 'synced' => null],
            ['key' => self::VISITS, 'label' => 'Service visits from calls', 'tag' => 'Visit', 'kind' => 'internal', 'connected' => true, 'status' => null, 'account' => null, 'synced' => null],
        ];

        foreach ($this->calendars->providers() as $key => $provider) {
            $connection = $connections->get($key);
            if (! $connection && ! $provider->isConfigured()) {
                continue;   // not offered yet: no chip for something nobody can connect
            }
            $sources[] = [
                'key' => $key,
                'label' => $provider->label(),
                'tag' => self::TAGS[$key] ?? $provider->label(),
                'kind' => 'external',
                'connected' => $connection !== null,
                'status' => $connection?->status,
                'account' => $connection?->account_email,
                'synced' => $connection?->last_synced_at?->diffForHumans(),
            ];
        }

        return $sources;
    }
}

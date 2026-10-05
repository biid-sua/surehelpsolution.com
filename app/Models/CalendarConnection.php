<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\StoresUtc;
use App\Services\Calendar\Data\PushChannel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A business's link to its Google or Microsoft calendar (spec §17–18).
 * Tokens are encrypted and hidden: they never reach the browser or the API.
 *
 * @property string $access_token
 * @property string|null $refresh_token
 * @property Carbon|null $token_expires_at
 * @property Carbon|null $last_synced_at
 * @property list<array{id: string, name: string, primary: bool, can_write: bool}>|null $calendars
 * @property list<string>|null $busy_calendar_ids
 * @property list<array{calendar_id: string, id: string, resource_id: ?string, expires_at: string}>|null $push_channels
 */
class CalendarConnection extends Model
{
    use BelongsToOrganization, StoresUtc;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_NEEDS_REAUTH = 'needs_reauth';

    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'organization_id', 'connected_by_user_id', 'provider', 'account_email', 'access_token', 'refresh_token',
        'token_expires_at', 'scopes', 'calendars', 'write_calendar_id', 'busy_calendar_ids', 'status',
        'last_synced_at', 'last_error', 'push_secret', 'push_channels',
    ];

    protected $hidden = ['access_token', 'refresh_token', 'push_secret'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'push_secret' => 'encrypted',
            'token_expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'calendars' => 'array',
            'busy_calendar_ids' => 'array',
            'push_channels' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CalendarConnection $connection) {
            $connection->ulid ??= (string) Str::ulid();
        });

        // Mirror the channel list into its index, so webhooks find the connection by channel id.
        static::saved(function (CalendarConnection $connection) {
            if ($connection->wasRecentlyCreated || $connection->wasChanged('push_channels')) {
                $connection->indexPushChannels();
            }
        });
    }

    public function indexPushChannels(): void
    {
        DB::transaction(function () {
            DB::table('calendar_push_channels')->where('calendar_connection_id', $this->id)->delete();
            foreach ($this->pushChannels() as $channel) {
                DB::table('calendar_push_channels')->upsert([[
                    'calendar_connection_id' => $this->id,
                    'provider' => $this->provider,
                    'channel_id' => mb_substr($channel->id, 0, 191),
                    'calendar_id' => $channel->calendarId,
                    'expires_at' => $channel->expiresAt->utc()->toDateTimeString(),
                ]], ['provider', 'channel_id'], ['calendar_connection_id', 'calendar_id', 'expires_at']);
            }
        });
    }

    /**
     * The active connection a provider's channel or subscription id belongs to.
     */
    public static function forPushChannel(string $provider, string $channelId): ?self
    {
        if ($channelId === '') {
            return null;
        }

        return static::withoutGlobalScopes()
            ->whereIn('id', DB::table('calendar_push_channels')->where('provider', $provider)->where('channel_id', $channelId)->select('calendar_connection_id'))
            ->where('status', self::STATUS_ACTIVE)
            ->first();
    }

    /** @return BelongsTo<User, $this> */
    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by_user_id');
    }

    /** @return HasMany<CalendarBusyBlock, $this> */
    public function busyBlocks(): HasMany
    {
        return $this->hasMany(CalendarBusyBlock::class);
    }

    /** Key under which an appointment stores its event id for this connection. */
    public function refKey(): string
    {
        return $this->provider.':'.$this->id;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function calendarName(?string $id): ?string
    {
        return collect($this->calendars ?? [])->firstWhere('id', $id)['name'] ?? null;
    }

    /**
     * @return list<PushChannel>
     */
    public function pushChannels(): array
    {
        return array_map(fn (array $c) => new PushChannel($c['calendar_id'], $c['id'], $c['resource_id'] ?? null, CarbonImmutable::parse($c['expires_at'])), $this->push_channels ?? []);
    }
}

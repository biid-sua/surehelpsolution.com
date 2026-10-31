<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Someone who asked a business for work (D109): from its website form, or a lead source sending to
 * its lead link. Records how fast they got an answer.
 *
 * @property string $ulid
 * @property string $source
 * @property array<string, mixed>|null $details
 * @property Carbon $received_at
 * @property Carbon|null $texted_at
 * @property Carbon|null $emailed_at
 * @property Carbon|null $first_response_at
 * @property Carbon|null $connected_at
 */
class Lead extends Model
{
    use BelongsToOrganization;

    public const SOURCES = [
        'website' => 'Website form',
        'facebook' => 'Facebook lead ad',
        'google' => 'Google (Local Services or Ads)',
        'angi' => 'Angi',
        'thumbtack' => 'Thumbtack',
        'yelp' => 'Yelp',
        'nextdoor' => 'Nextdoor',
        'other' => 'Other',
    ];

    /** The call that rings the owner and connects them (D109). */
    public const CALL_LABELS = [
        'calling_owner' => 'Ringing you',
        'owner_missed' => 'You missed the call',
        'connecting' => 'Connecting you',
        'connected' => 'You were connected',
        'lead_missed' => 'They didn\'t answer',
        'declined' => 'You chose not to call',
        'failed' => 'The call failed',
    ];

    protected $fillable = [
        'organization_id', 'customer_id', 'task_id', 'source', 'name', 'phone', 'email', 'message', 'details', 'received_at',
        'texted_at', 'emailed_at', 'first_response_at', 'call_status', 'call_sid', 'connected_at', 'note',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'received_at' => 'datetime',
            'texted_at' => 'datetime',
            'emailed_at' => 'datetime',
            'first_response_at' => 'datetime',
            'connected_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Lead $lead) {
            $lead->ulid ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function sourceLabel(): string
    {
        return self::SOURCES[$this->source] ?? self::SOURCES['other'];
    }

    public function firstName(): string
    {
        return (string) Str::of((string) $this->name)->trim()->before(' ');
    }

    /** Seconds from the lead arriving to the first text or email, or null when nothing went out yet. */
    public function responseSeconds(): ?int
    {
        return $this->first_response_at ? max(0, (int) $this->received_at->diffInSeconds($this->first_response_at)) : null;
    }
}

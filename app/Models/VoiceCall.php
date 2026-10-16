<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One phone call that reached a SureHelp line (D60): who called, who answered, how long it took,
 * and its recording, transcript and AI summary. The agent's notes live on the linked call log.
 *
 * @property list<int>|null $offered_to agents whose phones rang
 * @property list<array{speaker: string, text: string, at: string}>|null $transcript
 * @property Carbon $started_at
 * @property Carbon|null $answered_at
 * @property Carbon|null $ended_at
 * @property Carbon|null $transferred_at
 * @property Carbon|null $texted_back_at
 * @property int|null $wait_seconds
 * @property int|null $duration_seconds
 * @property int|null $recording_seconds
 */
class VoiceCall extends Model
{
    use BelongsToOrganization;

    public const RINGING = 'ringing';

    public const IN_PROGRESS = 'in_progress';

    public const COMPLETED = 'completed';

    public const MISSED = 'missed';

    public const VOICEMAIL = 'voicemail';

    public const TRANSFERRED = 'transferred';

    public const FAILED = 'failed';

    /** An agent's call out that nobody picked up. */
    public const NO_ANSWER = 'no_answer';

    public const STATUS_LABELS = [
        self::RINGING => 'Ringing',
        self::IN_PROGRESS => 'On the call',
        self::COMPLETED => 'Answered',
        self::MISSED => 'Missed',
        self::VOICEMAIL => 'Voicemail',
        self::TRANSFERRED => 'Sent to owner',
        self::FAILED => 'Failed',
        self::NO_ANSWER => 'Not answered',
    ];

    protected $fillable = [
        'organization_id', 'phone_number_id', 'provider', 'provider_call_sid', 'direction', 'after_hours', 'placed_by_user_id', 'texted_back_at', 'from_number', 'to_number',
        'status', 'offered_to', 'answered_by_user_id', 'customer_id', 'call_log_id', 'task_id',
        'started_at', 'answered_at', 'ended_at', 'wait_seconds', 'duration_seconds', 'transferred_to', 'transferred_at',
        'recording_sid', 'recording_seconds', 'transcript', 'summary', 'summary_status',
    ];

    protected function casts(): array
    {
        return [
            'offered_to' => 'array',
            'transcript' => 'array',
            'started_at' => 'datetime',
            'answered_at' => 'datetime',
            'ended_at' => 'datetime',
            'transferred_at' => 'datetime',
            'texted_back_at' => 'datetime',
            'after_hours' => 'boolean',
            'wait_seconds' => 'integer',
            'duration_seconds' => 'integer',
            'recording_seconds' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (VoiceCall $call) {
            $call->ulid ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<PhoneNumber, $this> */
    public function phoneNumber(): BelongsTo
    {
        return $this->belongsTo(PhoneNumber::class);
    }

    /** @return BelongsTo<User, $this> */
    public function answeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answered_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function placedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'placed_by_user_id');
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<CallLog, $this> */
    public function callLog(): BelongsTo
    {
        return $this->belongsTo(CallLog::class);
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @param Builder<VoiceCall> $query */
    public function scopeLive(Builder $query): void
    {
        $query->whereIn('status', [self::RINGING, self::IN_PROGRESS]);
    }

    public function isLive(): bool
    {
        return in_array($this->status, [self::RINGING, self::IN_PROGRESS], true);
    }

    public function wasOfferedTo(User $user): bool
    {
        return in_array($user->id, array_map('intval', $this->offered_to ?? []), true);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? Str::headline($this->status);
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            self::COMPLETED, self::TRANSFERRED => 'success',
            self::MISSED, self::FAILED => 'danger',
            self::NO_ANSWER => 'neutral',
            self::VOICEMAIL => 'warning',
            default => 'info',
        };
    }

    public function hasRecording(): bool
    {
        return $this->recording_sid !== null;
    }

    public function isOutbound(): bool
    {
        return $this->direction === 'outbound';
    }

    /** The other party: the caller for calls in, the number called for calls out. */
    public function otherParty(): string
    {
        $number = $this->isOutbound() ? $this->to_number : $this->from_number;

        return $number ? PhoneNumber::pretty($number) : 'Unknown number';
    }

    public function fromDisplay(): string
    {
        return $this->from_number ? PhoneNumber::pretty($this->from_number) : 'Unknown number';
    }

    /** "3:07" */
    public static function clock(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }

        return intdiv($seconds, 60).':'.str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT);
    }
}

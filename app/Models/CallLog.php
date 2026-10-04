<?php

namespace App\Models;

use App\Enums\CallOwnershipSource;
use App\Enums\OutcomeCategory;
use App\Models\Concerns\BelongsToOrganization;
use App\Services\Calls\CallOutcomes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CallLog extends Model
{
    use BelongsToOrganization;

    /**
     * Shown in place of empty values in the UI (never the literal "null").
     */
    public const EMPTY_PLACEHOLDER = '—';

    /**
     * Allowed values of the `status` column (must match the DB enum).
     */
    public const STATUSES = [
        'new',
        'service-requested',
        'information-provided',
        'cancelled',
        'spam',
        'completed',
    ];

    /**
     * Human labels for the `status` column.
     */
    public const STATUS_LABELS = [
        'new' => 'New',
        'service-requested' => 'In Progress',
        'information-provided' => 'Info Provided',
        'cancelled' => 'Cancelled',
        'spam' => 'Spam',
        'completed' => 'Completed',
    ];

    /**
     * Call outcomes that describe the call better than its status does.
     */
    public const OUTCOME_LABELS = [
        'scheduled-appointment' => 'Scheduled',
        'call-dropped' => 'Dropped',
        'no-response' => 'No Response',
        'wrong-number' => 'Spam',
        'callback-requested' => 'Callback Requested',
        'followup-scheduled' => 'Follow-Up Scheduled',
    ];

    protected $fillable = [
        'call_id',
        'client_id',
        'organization_id',
        'customer_id',
        'ownership_source',
        'call_date',
        'call_time',
        'caller_name',
        'caller_phone',
        'caller_email',
        'reason_for_call',
        'call_outcome',
        'agent_name',
        'status',
        'service_request',
        'service_date',
        'service_window',
        'service_location',
        'notes',
        'user_id',
    ];

    protected $casts = [
        'call_date' => 'date',
        'service_date' => 'date',
        'service_request' => 'boolean',
        'call_time' => 'datetime:H:i',
        'ownership_source' => CallOwnershipSource::class,
    ];

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Tasks created from this call (call-backs, spec §24).
     *
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'call_log_id');
    }

    /**
     * Get the user that owns the call log.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The real status of the call for display (FIX-02).
     *
     * Terminal outcomes (dropped, spam, scheduled...) win over the generic
     * status, otherwise the stored status is shown as-is.
     */
    public function statusLabel(): string
    {
        if ($this->status === 'completed') {
            return self::STATUS_LABELS['completed'];
        }

        $outcome = $this->call_outcome ? app(CallOutcomes::class)->effective($this->organization_id)->get($this->call_outcome) : null;

        // The business's own wording wins: its custom outcomes and renamed defaults.
        if ($outcome && ($outcome['custom'] || $outcome['overridden'])) {
            return $outcome['label'];
        }

        if (isset(self::OUTCOME_LABELS[$this->call_outcome])) {
            return self::OUTCOME_LABELS[$this->call_outcome];
        }

        // Categories that say more than the status does (booked, callback, escalated, missed, spam).
        $category = $outcome['category'] ?? null;
        if ($category && ! in_array($category, [OutcomeCategory::Information, OutcomeCategory::Other], true)) {
            return $category->badge();
        }

        if ($this->service_date) {
            return 'Scheduled';
        }

        $status = (string) $this->getRawOriginal('status', $this->getAttribute('status'));

        // Defensive: show unknown future values readably instead of failing.
        return self::STATUS_LABELS[$status] ?? ($status === '' ? 'New' : Str::headline($status));
    }

    /**
     * Badge colour group for the status label.
     */
    public function statusTone(): string
    {
        return match ($this->statusLabel()) {
            'Completed' => 'completed',
            'Scheduled', 'Follow-Up Scheduled' => 'scheduled',
            'In Progress', 'Callback Requested' => 'progress',
            'Dropped', 'No Response', 'Cancelled', 'Spam', 'Missed' => 'danger',
            'Escalated' => 'progress',
            default => $this->outcomeCategory()?->tone() ?? 'neutral',
        };
    }

    /**
     * What this call's outcome means for its business; null for unknown legacy values.
     */
    public function outcomeCategory(): ?OutcomeCategory
    {
        return app(CallOutcomes::class)->category($this->organization_id, $this->call_outcome);
    }

    /**
     * Whether this call resulted in a service visit worth showing on a calendar.
     */
    public function hasScheduledService(): bool
    {
        return $this->service_request
            || $this->service_date !== null
            || $this->outcomeCategory() === OutcomeCategory::Booked;
    }

    /**
     * Format a possibly-empty value for display (FIX-03).
     */
    public static function display(mixed $value): string
    {
        if ($value === null) {
            return self::EMPTY_PLACEHOLDER;
        }

        $value = trim((string) $value);

        return ($value === '' || strtolower($value) === 'null') ? self::EMPTY_PLACEHOLDER : $value;
    }

    /**
     * Generate the next call ID for the day, e.g. CL-20261003-0001 (FIX-04).
     *
     * IDs are reserved from a per-day counter row locked inside a transaction,
     * so two agents saving at the same moment can never receive the same ID.
     */
    public static function generateCallId(): string
    {
        $date = now()->toDateString();
        $prefix = 'CL-'.str_replace('-', '', $date).'-';

        $sequence = DB::transaction(function () use ($date, $prefix) {
            DB::table('call_id_sequences')->insertOrIgnore([
                'date' => $date,
                'last_sequence' => self::highestSequenceFor($prefix),
            ]);

            $current = DB::table('call_id_sequences')
                ->where('date', $date)
                ->lockForUpdate()
                ->value('last_sequence');

            $next = $current + 1;

            DB::table('call_id_sequences')
                ->where('date', $date)
                ->update(['last_sequence' => $next]);

            return $next;
        });

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Highest sequence already used for a prefix, so the counter starts after existing IDs.
     */
    private static function highestSequenceFor(string $prefix): int
    {
        // Call IDs are global, so look across every organization.
        return (int) static::withoutGlobalScopes()
            ->where('call_id', 'like', $prefix.'%')
            ->pluck('call_id')
            ->map(fn ($id) => (int) Str::afterLast($id, '-'))
            ->max();
    }
}

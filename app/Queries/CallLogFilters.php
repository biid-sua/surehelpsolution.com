<?php

namespace App\Queries;

use App\Models\CallLog;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filters for an organization's call list. Shared by the calls table and the
 * CSV export so they always return the same rows.
 */
class CallLogFilters
{
    /** Quick-filter views shown as tabs (key => label). */
    public const VIEWS = [
        'all' => 'All calls',
        'follow_up' => 'Needs follow-up',
        'scheduled' => 'Scheduled',
        'service' => 'Service requests',
        'missed' => 'Missed / dropped',
        'spam' => 'Spam',
    ];

    public function __construct(
        public string $search = '',
        public string $view = 'all',
        public ?string $from = null,
        public ?string $to = null,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        return new self(
            search: trim((string) ($input['search'] ?? '')),
            view: array_key_exists($input['view'] ?? 'all', self::VIEWS) ? (string) ($input['view'] ?? 'all') : 'all',
            from: self::date($input['from'] ?? null),
            to: self::date($input['to'] ?? null),
        );
    }

    /**
     * @return Builder<CallLog>
     */
    public function apply(Organization $organization): Builder
    {
        $query = CallLog::query()->forOrganization($organization);
        $timezone = $organization->timezone ?: config('app.timezone');

        if ($this->search !== '') {
            $term = '%'.addcslashes($this->search, '%_\\').'%';
            $query->where(fn (Builder $q) => $q
                ->where('caller_name', 'like', $term)
                ->orWhere('caller_phone', 'like', $term)
                ->orWhere('caller_email', 'like', $term)
                ->orWhere('call_id', 'like', $term)
                ->orWhere('reason_for_call', 'like', $term)
                ->orWhere('notes', 'like', $term));
        }

        match ($this->view) {
            'follow_up' => self::pendingFollowUps($query),
            'scheduled' => $query->where(fn (Builder $q) => $q->where('call_outcome', 'scheduled-appointment')->orWhereNotNull('service_date')),
            'service' => $query->where('service_request', true),
            'missed' => $query->whereIn('call_outcome', ['call-dropped', 'no-response']),
            'spam' => $query->where(fn (Builder $q) => $q->where('status', 'spam')->orWhere('call_outcome', 'wrong-number')),
            default => null,
        };

        // Date range is interpreted in the business's timezone, stored timestamps are UTC (spec §74).
        if ($this->from) {
            $query->where('created_at', '>=', CarbonImmutable::parse($this->from, $timezone)->startOfDay()->utc());
        }
        if ($this->to) {
            $query->where('created_at', '<=', CarbonImmutable::parse($this->to, $timezone)->endOfDay()->utc());
        }

        return $query->latest('created_at')->latest('id');
    }

    /**
     * Callbacks / follow-ups that nobody has closed yet.
     *
     * @param  Builder<CallLog>  $query
     * @return Builder<CallLog>
     */
    public static function pendingFollowUps(Builder $query): Builder
    {
        return $query->whereIn('call_outcome', ['callback-requested', 'followup-scheduled'])
            ->whereNotIn('status', ['completed', 'cancelled', 'spam']);
    }

    private static function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)) ? $value : null;
    }
}

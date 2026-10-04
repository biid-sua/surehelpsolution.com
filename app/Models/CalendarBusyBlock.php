<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\StoresUtc;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Time that is busy in a connected external calendar (times only, no details).
 *
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 */
class CalendarBusyBlock extends Model
{
    use BelongsToOrganization, StoresUtc;

    public $timestamps = false;

    protected $fillable = ['organization_id', 'calendar_connection_id', 'calendar_id', 'external_event_id', 'starts_at', 'ends_at', 'all_day'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'all_day' => 'boolean'];
    }

    /** @return BelongsTo<CalendarConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(CalendarConnection::class, 'calendar_connection_id');
    }

    /**
     * Busy times from a connection that needs reconnecting still count: stale but safer than double-booking.
     *
     * @param  Builder<CalendarBusyBlock>  $query
     */
    public function scopeOverlapping(Builder $query, CarbonInterface $start, CarbonInterface $end): void
    {
        $query->where('starts_at', '<', $end)->where('ends_at', '>', $start);
    }
}

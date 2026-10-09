<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A shift copied into an agent's own calendar: which event, in which calendar, as of which version. */
class AgentCalendarEvent extends Model
{
    protected $fillable = ['connection_id', 'shift_id', 'external_id', 'calendar_id', 'fingerprint'];

    /** @return BelongsTo<AgentCalendarConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(AgentCalendarConnection::class, 'connection_id');
    }
}

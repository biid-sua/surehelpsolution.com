<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message in a support request; may carry one attachment, stored privately.
 */
class SupportTicketMessage extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['support_ticket_id', 'organization_id', 'user_id', 'is_staff', 'body', 'attachment_path', 'attachment_name', 'attachment_size'];

    protected function casts(): array
    {
        return ['is_staff' => 'boolean', 'attachment_size' => 'integer'];
    }

    /** @return BelongsTo<SupportTicket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

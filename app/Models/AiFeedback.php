<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * A team member's rating of one AI reply, with an optional correction (D39).
 */
class AiFeedback extends Model
{
    use BelongsToOrganization;

    protected $table = 'ai_feedback';

    protected $fillable = ['organization_id', 'message_id', 'user_id', 'rating', 'correction', 'ai_guideline_id'];
}

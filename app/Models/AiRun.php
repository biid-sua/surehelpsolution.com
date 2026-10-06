<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * One time the AI assistant worked on a conversation: what it did, which tools it used, what it cost (spec §38).
 *
 * @property list<array{name: string, input: array<string, mixed>, ok: bool, summary: string}>|null $tool_calls
 */
class AiRun extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'conversation_id', 'mode', 'model', 'status', 'reason', 'steps', 'input_tokens', 'output_tokens',
        'cache_read_tokens', 'tool_calls',
    ];

    protected function casts(): array
    {
        return ['tool_calls' => 'array'];
    }
}

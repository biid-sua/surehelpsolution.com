<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One request to the AI content studio (G-2, D66): writing or rewriting a post, or planning a month.
 * Its tokens count towards the business's monthly allowance.
 *
 * @property int $id
 * @property int $organization_id
 * @property int|null $user_id
 * @property string $kind write | rewrite | plan
 * @property string $status queued | running | done | failed
 * @property array<string, mixed>|null $input
 * @property array<string, mixed>|null $output
 * @property string|null $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property string|null $error
 */
class SocialAiRun extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'user_id', 'kind', 'status', 'input', 'output', 'model', 'input_tokens', 'output_tokens', 'error'];

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'output' => 'array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

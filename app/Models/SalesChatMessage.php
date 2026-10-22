<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of a website sales chat: from the visitor, the assistant, or a person on the SureHelp team. */
class SalesChatMessage extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $casts = ['by_ai' => 'boolean'];

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    /** @return BelongsTo<SalesChat, $this> */
    public function chat(): BelongsTo
    {
        return $this->belongsTo(SalesChat::class, 'sales_chat_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array{id: string, from: string, text: string, at: string} */
    public function toWidget(): array
    {
        return [
            'id' => $this->ulid,
            'from' => ['visitor' => 'you', 'assistant' => 'assistant', 'team' => 'team'][$this->author] ?? 'assistant',
            'text' => $this->body,
            'at' => $this->created_at->toIso8601String(),
        ];
    }
}

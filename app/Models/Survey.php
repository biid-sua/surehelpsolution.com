<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\StoresUtc;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One satisfaction survey sent to a customer (D84).
 *
 * @property Carbon $sent_at
 * @property Carbon $expires_at
 * @property Carbon|null $answered_at
 * @property Carbon|null $review_clicked_at
 */
class Survey extends Model
{
    use BelongsToOrganization, StoresUtc;

    /** Scores at or above this are "happy": they're offered the review link. */
    public const HAPPY = 4;

    protected $fillable = [
        'organization_id', 'customer_id', 'automation_id', 'subject_type', 'subject_id', 'agent_user_id', 'token', 'channel', 'review_url',
        'sent_at', 'expires_at', 'score', 'answered_by', 'answered_at', 'feedback', 'review_clicked_at', 'task_id',
    ];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'expires_at' => 'datetime', 'answered_at' => 'datetime', 'review_clicked_at' => 'datetime', 'score' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (Survey $survey) {
            $survey->ulid ??= (string) Str::ulid();
            $survey->token ??= Str::random(32);
        });
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_user_id');
    }

    public function isOpen(): bool
    {
        return $this->expires_at->isFuture() && $this->feedback === null;
    }

    public function link(): string
    {
        return route('survey.show', $this->token);
    }
}

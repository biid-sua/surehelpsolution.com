<?php

namespace App\Models;

use App\Enums\QuestionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * An assessment question in a quiz lesson's draft (brief §1.10). Options are stored with the
 * question as [{id, label, correct}]; option ids stay stable when the text is edited.
 *
 * @property QuestionType $type
 * @property list<array{id: string, label: string, correct: bool}> $options
 */
class TrainingQuestion extends Model
{
    protected $fillable = ['lesson_id', 'type', 'scenario', 'prompt', 'explanation', 'options', 'position'];

    protected function casts(): array
    {
        return ['type' => QuestionType::class, 'options' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(function (TrainingQuestion $question) {
            $question->ulid ??= (string) Str::ulid();
        });
    }

    /** @return BelongsTo<TrainingLesson, $this> */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(TrainingLesson::class, 'lesson_id');
    }

    /** A short random id for a new option. */
    public static function optionId(): string
    {
        return Str::lower(Str::random(8));
    }
}

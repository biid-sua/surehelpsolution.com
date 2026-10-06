<?php

namespace App\Enums;

/** Assessment question types (brief §1.10). */
enum QuestionType: string
{
    case Single = 'single';
    case Multiple = 'multiple';
    case TrueFalse = 'true_false';
    case Scenario = 'scenario';

    public function label(): string
    {
        return match ($this) {
            self::Single => 'One correct answer',
            self::Multiple => 'Several correct answers',
            self::TrueFalse => 'True or false',
            self::Scenario => 'Scenario',
        };
    }

    /** Whether more than one option may be correct (and chosen). */
    public function allowsMany(): bool
    {
        return $this === self::Multiple;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $t) => [$t->value => $t->label()])->all();
    }
}

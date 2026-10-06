<?php

namespace App\Enums;

/**
 * What a lesson holds (spec §20B, brief §1.3). Not built around video: every type is a lesson with
 * an optional file, link or text body, and a quiz is a lesson with questions.
 */
enum LessonType: string
{
    case Text = 'text';
    case Video = 'video';
    case Pdf = 'pdf';
    case Document = 'document';
    case Presentation = 'presentation';
    case Audio = 'audio';
    case External = 'external';
    case Quiz = 'quiz';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Reading',
            self::Video => 'Video',
            self::Pdf => 'PDF',
            self::Document => 'Document',
            self::Presentation => 'Presentation',
            self::Audio => 'Audio',
            self::External => 'External resource',
            self::Quiz => 'Quiz',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Text => 'list',
            self::Video => 'photo',
            self::Pdf, self::Document, self::Presentation => 'download',
            self::Audio => 'phone',
            self::External => 'chevron-right',
            self::Quiz => 'check-circle',
        };
    }

    /** Types whose content can be an uploaded file. */
    public function acceptsFile(): bool
    {
        return in_array($this, [self::Video, self::Pdf, self::Document, self::Presentation, self::Audio], true);
    }

    /** Types whose content can be a link (video sites, external courses). */
    public function acceptsUrl(): bool
    {
        return in_array($this, [self::Video, self::External, self::Presentation, self::Document], true);
    }

    /** @return list<string> file extensions allowed for an upload of this type */
    public function extensions(): array
    {
        return match ($this) {
            self::Video => ['mp4', 'webm', 'mov'],
            self::Pdf => ['pdf'],
            self::Document => ['pdf', 'doc', 'docx', 'odt', 'txt', 'rtf'],
            self::Presentation => ['pdf', 'ppt', 'pptx', 'odp'],
            self::Audio => ['mp3', 'm4a', 'wav', 'ogg'],
            default => [],
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $t) => [$t->value => $t->label()])->all();
    }
}

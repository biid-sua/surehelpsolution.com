<?php

namespace App\Enums;

/**
 * A post's lifecycle (spec §41): draft → in review → scheduled → publishing → published,
 * partly published or failed. Cancelled posts stay for the record.
 */
enum SocialPostStatus: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case Scheduled = 'scheduled';
    case Publishing = 'publishing';
    case Published = 'published';
    case PartlyPublished = 'partly_published';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::InReview => 'Waiting for approval',
            self::Scheduled => 'Scheduled',
            self::Publishing => 'Publishing',
            self::Published => 'Published',
            self::PartlyPublished => 'Partly published',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Draft, self::Cancelled => 'neutral',
            self::InReview => 'warning',
            self::Scheduled, self::Publishing => 'info',
            self::Published => 'success',
            self::PartlyPublished, self::Failed => 'danger',
        };
    }

    /** Can still be edited (nothing has gone out yet). */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::InReview, self::Scheduled], true);
    }
}

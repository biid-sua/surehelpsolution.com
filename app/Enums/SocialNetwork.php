<?php

namespace App\Enums;

/**
 * Networks a business can publish to (spec §41, D33). Each one is reached through a connector
 * (the OAuth app) and published to by its own adapter.
 */
enum SocialNetwork: string
{
    case Facebook = 'facebook';
    case Instagram = 'instagram';
    case LinkedIn = 'linkedin';
    case GoogleBusiness = 'google_business';

    public function label(): string
    {
        return match ($this) {
            self::Facebook => 'Facebook',
            self::Instagram => 'Instagram',
            self::LinkedIn => 'LinkedIn',
            self::GoogleBusiness => 'Google Business Profile',
        };
    }

    public function shortLabel(): string
    {
        return $this === self::GoogleBusiness ? 'Google' : $this->label();
    }

    /** The OAuth connector that grants access to this network's accounts. */
    public function connector(): string
    {
        return match ($this) {
            self::Facebook, self::Instagram => 'meta',
            self::LinkedIn => 'linkedin',
            self::GoogleBusiness => 'google',
        };
    }

    /** Accent used for chips and calendar entries. */
    public function color(): string
    {
        return match ($this) {
            self::Facebook => '#1877F2',
            self::Instagram => '#E1306C',
            self::LinkedIn => '#0A66C2',
            self::GoogleBusiness => '#34A853',
        };
    }

    /**
     * What a post on this network can contain. Checked before a post can be scheduled.
     *
     * @return array{max_chars: int, requires_media: bool, max_images: int, links: bool, clickable_links: bool, max_hashtags: ?int}
     */
    public function capabilities(): array
    {
        return match ($this) {
            self::Facebook => ['max_chars' => 63206, 'requires_media' => false, 'max_images' => 10, 'links' => true, 'clickable_links' => true, 'max_hashtags' => null],
            self::Instagram => ['max_chars' => 2200, 'requires_media' => true, 'max_images' => 10, 'links' => false, 'clickable_links' => false, 'max_hashtags' => 30],
            self::LinkedIn => ['max_chars' => 3000, 'requires_media' => false, 'max_images' => 1, 'links' => true, 'clickable_links' => true, 'max_hashtags' => null],
            self::GoogleBusiness => ['max_chars' => 1500, 'requires_media' => false, 'max_images' => 1, 'links' => true, 'clickable_links' => false, 'max_hashtags' => null],
        };
    }
}

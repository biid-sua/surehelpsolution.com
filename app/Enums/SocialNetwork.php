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
    case YouTube = 'youtube';
    case X = 'x';

    public function label(): string
    {
        return match ($this) {
            self::Facebook => 'Facebook',
            self::Instagram => 'Instagram',
            self::LinkedIn => 'LinkedIn',
            self::GoogleBusiness => 'Google Business Profile',
            self::YouTube => 'YouTube',
            self::X => 'X',
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
            self::YouTube => 'youtube',
            self::X => 'x',
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
            self::YouTube => '#FF0000',
            self::X => '#71767B',
        };
    }

    /**
     * What a post on this network can contain. Checked before a post can be scheduled.
     * `videos`: how many videos a post can carry (0: none); `requires_video`: video posts only (YouTube).
     *
     * @return array{max_chars: int, requires_media: bool, max_images: int, links: bool, clickable_links: bool, max_hashtags: ?int, videos: int, requires_video: bool, max_video_seconds: ?int}
     */
    public function capabilities(): array
    {
        $none = ['videos' => 1, 'requires_video' => false, 'max_video_seconds' => null];

        return match ($this) {
            self::Facebook => ['max_chars' => 63206, 'requires_media' => false, 'max_images' => 10, 'links' => true, 'clickable_links' => true, 'max_hashtags' => null] + $none,
            // Videos go out as Reels (up to 15 minutes).
            self::Instagram => ['max_chars' => 2200, 'requires_media' => true, 'max_images' => 10, 'links' => false, 'clickable_links' => false, 'max_hashtags' => 30, 'max_video_seconds' => 900] + $none,
            self::LinkedIn => ['max_chars' => 3000, 'requires_media' => false, 'max_images' => 1, 'links' => true, 'clickable_links' => true, 'max_hashtags' => null, 'max_video_seconds' => 1800] + $none,
            self::GoogleBusiness => ['max_chars' => 1500, 'requires_media' => false, 'max_images' => 1, 'links' => true, 'clickable_links' => false, 'max_hashtags' => null, 'videos' => 0] + $none,
            // The text becomes the video's description; its first line, the title.
            self::YouTube => ['max_chars' => 5000, 'requires_media' => true, 'max_images' => 0, 'links' => true, 'clickable_links' => true, 'max_hashtags' => 15, 'requires_video' => true] + $none,
            self::X => ['max_chars' => 280, 'requires_media' => false, 'max_images' => 4, 'links' => true, 'clickable_links' => true, 'max_hashtags' => null, 'max_video_seconds' => 140] + $none,
        };
    }
}

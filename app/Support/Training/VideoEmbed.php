<?php

namespace App\Support\Training;

/**
 * Turns a YouTube or Vimeo link into a privacy-friendly player URL (D42: video can be uploaded or
 * linked). Any other link is opened as an external resource instead of being embedded.
 */
class VideoEmbed
{
    public static function url(?string $link): ?string
    {
        if (! $link) {
            return null;
        }
        $patterns = [
            '~^https?://(?:www\.|m\.)?youtube\.com/watch\?(?:.*&)?v=([\w-]{11})~i' => 'https://www.youtube-nocookie.com/embed/%s',
            '~^https?://(?:www\.)?youtube\.com/(?:embed|shorts|live)/([\w-]{11})~i' => 'https://www.youtube-nocookie.com/embed/%s',
            '~^https?://youtu\.be/([\w-]{11})~i' => 'https://www.youtube-nocookie.com/embed/%s',
            '~^https?://(?:www\.)?vimeo\.com/(?:video/)?(\d+)~i' => 'https://player.vimeo.com/video/%s?dnt=1',
            '~^https?://player\.vimeo\.com/video/(\d+)~i' => 'https://player.vimeo.com/video/%s?dnt=1',
        ];
        foreach ($patterns as $pattern => $embed) {
            if (preg_match($pattern, $link, $m)) {
                return sprintf($embed, $m[1]);
            }
        }

        return null;
    }
}

<?php

namespace App\Services\Social;

use App\Enums\SocialNetwork;

/**
 * Checks one version of a post against what its network accepts (spec §41: "validated against each
 * channel's rules before it can be scheduled"). Errors block scheduling; warnings are advice.
 */
class PostValidator
{
    /**
     * @param  int  $imageCount  images attached to the post
     * @return array{errors: list<string>, warnings: list<string>}
     */
    public function check(SocialNetwork $network, string $text, ?string $link, int $imageCount): array
    {
        $caps = $network->capabilities();
        $errors = [];
        $warnings = [];
        $length = mb_strlen($text);
        $name = $network->label();

        if (trim($text) === '' && $imageCount === 0) {
            $errors[] = 'Write something or add a photo.';
        }
        if ($length > $caps['max_chars']) {
            $errors[] = "{$name} allows {$caps['max_chars']} characters; this is {$length}.";
        }
        if ($caps['requires_media'] && $imageCount === 0) {
            $errors[] = "{$name} posts need at least one photo.";
        }
        if ($imageCount > $caps['max_images']) {
            if ($caps['max_images'] === 1) {
                $warnings[] = "{$name} posts can have one photo; only the first will be used.";
            } else {
                $errors[] = "{$name} posts can have up to {$caps['max_images']} photos.";
            }
        }
        if ($caps['max_hashtags'] !== null && ($tags = preg_match_all('/(?<![\p{L}\p{N}])#[\p{L}\p{N}_]+/u', $text)) > $caps['max_hashtags']) {
            $errors[] = "{$name} allows {$caps['max_hashtags']} hashtags; this has {$tags}.";
        }

        if ($network === SocialNetwork::GoogleBusiness && preg_match('/(\+?\d[\d\s().-]{7,}\d)/', $text)) {
            // Google removes posts that contain phone numbers; the "Call now" button uses the profile's number.
            $errors[] = 'Google doesn\'t allow phone numbers in posts. Remove it and use the "Call now" button instead.';
        }

        if ($link && ! $caps['clickable_links'] && $network === SocialNetwork::Instagram) {
            $warnings[] = 'Links aren\'t clickable on Instagram. Say "link in bio" or leave the link out.';
        }
        if ($network === SocialNetwork::LinkedIn && $link && $imageCount > 0) {
            $warnings[] = 'LinkedIn shows either a photo or a link preview: the photo wins, so the link stays in the text only.';
        }
        if ($network === SocialNetwork::Facebook && $link && $imageCount > 0) {
            $warnings[] = 'With a photo, Facebook doesn\'t show a link preview; the link is added to the text.';
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }
}

<?php

namespace App\Services\Inbox;

use App\Models\Organization;
use App\Services\Telephony\TelephonyProvider;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Pictures texted to a business (MMS, D62). Copied from the vendor into private storage, so they
 * stay after the vendor deletes them, and shown only to people who can read the business's Inbox.
 */
class TextedPictures
{
    public const MAX_PICTURES = 5;

    public const MAX_BYTES = 5 * 1024 * 1024;

    public const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/heic' => 'heic'];

    public function __construct(private readonly TelephonyProvider $provider) {}

    /**
     * @param  list<string>  $urls  the vendor's media links
     * @return array{attachments: list<array{type: string, url: string, name: string}>, skipped: int}
     */
    public function store(Organization $organization, array $urls): array
    {
        $attachments = [];
        $skipped = 0;
        foreach (array_slice($urls, 0, self::MAX_PICTURES) as $url) {
            $media = $this->provider->downloadMedia($url);
            $extension = $media ? (self::TYPES[$media['type']] ?? null) : null;
            if (! $media || ! $extension || strlen($media['body']) > self::MAX_BYTES) {
                $skipped++;

                continue;
            }
            $file = Str::lower((string) Str::ulid()).'.'.$extension;
            Storage::disk('local')->put(self::path($organization->ulid, $file), $media['body']);
            $attachments[] = ['type' => 'image', 'url' => route('inbox.media', [$organization->ulid, $file]), 'name' => 'Picture.'.$extension];
        }

        return ['attachments' => $attachments, 'skipped' => $skipped + max(0, count($urls) - self::MAX_PICTURES)];
    }

    public static function path(string $organizationUlid, string $file): string
    {
        return 'inbox-media/'.$organizationUlid.'/'.$file;
    }
}

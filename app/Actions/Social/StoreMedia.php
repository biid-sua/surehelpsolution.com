<?php

namespace App\Actions\Social;

use App\Models\MediaAsset;
use App\Models\Organization;
use App\Models\User;
use App\Support\Media\VideoLength;
use App\Support\Audit\Audit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Saves an uploaded photo or video to the business's private media library (D104 adds videos).
 */
class StoreMedia
{
    public function __construct(private readonly Audit $audit) {}

    public function handle(Organization $organization, UploadedFile $file, User $actor, ?string $alt = null): MediaAsset
    {
        $mime = (string) ($file->getMimeType() ?: 'image/jpeg');
        $named = strtolower($file->getClientOriginalExtension());
        // Some MP4/MOV files aren't recognised from their contents: trust the video extensions too.
        if (! str_starts_with($mime, 'video/') && in_array($named, (array) config('social.media.video_mimes'), true)) {
            $mime = $named === 'mov' ? 'video/quicktime' : 'video/mp4';
        }
        $video = str_starts_with($mime, 'video/');
        $extension = $video ? ($named ?: 'mp4') : strtolower($file->guessExtension() ?: $named ?: 'jpg');
        $path = $file->storeAs('social/'.$organization->ulid, Str::ulid().'.'.$extension, 'local');
        $size = $video ? [null, null] : (@getimagesize($file->getRealPath()) ?: [null, null]);

        $asset = MediaAsset::create([
            'organization_id' => $organization->id,
            'disk' => 'local',
            'path' => $path,
            'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'mime' => $mime,
            'duration_seconds' => $video ? VideoLength::of($file->getRealPath()) : null,
            'size_bytes' => (int) $file->getSize(),
            'width' => $size[0],
            'height' => $size[1],
            'alt_text' => filled($alt) ? Str::limit(trim((string) $alt), 500, '') : null,
            'uploaded_by_user_id' => $actor->id,
        ]);

        $this->audit->record('media.uploaded', $asset, new: ['name' => $asset->original_name, 'size' => $asset->size_bytes], organization: $organization, actor: $actor, label: $asset->original_name);

        return $asset;
    }
}

<?php

namespace App\Actions\Social;

use App\Models\MediaAsset;
use App\Models\Organization;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Saves an uploaded image to the business's private media library.
 */
class StoreMedia
{
    public function __construct(private readonly Audit $audit) {}

    public function handle(Organization $organization, UploadedFile $file, User $actor, ?string $alt = null): MediaAsset
    {
        $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'jpg');
        $path = $file->storeAs('social/'.$organization->ulid, Str::ulid().'.'.$extension, 'local');
        $size = @getimagesize($file->getRealPath()) ?: [null, null];

        $asset = MediaAsset::create([
            'organization_id' => $organization->id,
            'disk' => 'local',
            'path' => $path,
            'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'mime' => (string) ($file->getMimeType() ?: 'image/jpeg'),
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

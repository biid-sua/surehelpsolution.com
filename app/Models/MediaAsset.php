<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * An image in a business's media library (spec §41). Files are private; the business sees them through
 * an authorised route, and networks download them from a short-lived signed link.
 */
class MediaAsset extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'disk', 'path', 'original_name', 'mime', 'size_bytes', 'width', 'height', 'alt_text', 'uploaded_by_user_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (MediaAsset $asset) {
            $asset->ulid ??= (string) Str::ulid();
        });

        static::deleted(function (MediaAsset $asset) {
            Storage::disk($asset->disk)->delete($asset->path);
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /** For the business's own screens (signed-in, tenant-checked). */
    public function url(): string
    {
        return route('app.social.media.show', $this);
    }

    /** For Facebook, Instagram, LinkedIn and Google to fetch the file while publishing. */
    public function publicUrl(): string
    {
        return URL::temporarySignedRoute('media.public', now()->addMinutes((int) config('social.media.link_minutes', 120)), ['asset' => $this->ulid]);
    }
}

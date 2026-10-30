<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * An image or video in a business's media library (spec §41, D104). Pictures can also be made by AI. Files are private; the business sees them through
 * an authorised route, and networks download them from a short-lived signed link.
 *
 * @property Carbon|null $last_used_at
 * @property int|null $duration_seconds
 */
class MediaAsset extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'disk', 'path', 'original_name', 'mime', 'size_bytes', 'width', 'height', 'alt_text', 'uploaded_by_user_id',
        'source', 'ai_prompt', 'duration_seconds', 'last_used_at',
    ];

    public const SOURCE_UPLOAD = 'upload';

    public const SOURCE_AI = 'ai';

    protected $attributes = ['source' => self::SOURCE_UPLOAD];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'duration_seconds' => 'integer', 'size_bytes' => 'integer'];
    }

    public function isVideo(): bool
    {
        return str_starts_with((string) $this->mime, 'video/');
    }

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
        // SureHelp's own library is shown in the admin console (D104). The current business is
        // usually this one, which saves loading it for every picture in a list.
        $current = app(CurrentOrganization::class)->get();
        $internal = $current?->id === $this->organization_id ? $current->is_internal : $this->organization?->is_internal;

        return route($internal ? 'admin.social.media.show' : 'app.social.media.show', $this);
    }

    /** For Facebook, Instagram, LinkedIn and Google to fetch the file while publishing. */
    public function publicUrl(): string
    {
        return URL::temporarySignedRoute('media.public', now()->addMinutes((int) config('social.media.link_minutes', 120)), ['asset' => $this->ulid]);
    }
}

<?php

namespace App\Models;

use App\Support\FileSize;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A full data export of one business (task.md CMP-07), built in the background.
 *
 * @property Carbon|null $completed_at
 * @property Carbon|null $expires_at
 */
class DataExport extends Model
{
    public const PENDING = 'pending';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    protected $fillable = ['organization_id', 'requested_by', 'status'];

    protected $attributes = ['status' => self::PENDING];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
            'size_bytes' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (DataExport $export) {
            $export->ulid ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isDownloadable(): bool
    {
        return $this->status === self::READY && $this->path !== null && $this->expires_at?->isFuture();
    }

    /** "840 KB", "2.4 MB". */
    public function sizeLabel(): ?string
    {
        return FileSize::label($this->size_bytes ? (int) $this->size_bytes : null);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Small key/value settings the SureHelp team edits in the admin console (not per business).
 */
class PlatformSetting extends Model
{
    public const CREATED_AT = null;

    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::query()->find($key)->value ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}

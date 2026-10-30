<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One setting from Admin › Connections (D104), stored encrypted. Read through App\Services\Connections\Connections.
 *
 * @property string $key
 * @property string $value
 * @property int|null $updated_by
 */
class PlatformSecret extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $fillable = ['key', 'value', 'updated_by'];

    protected $hidden = ['value'];

    protected function casts(): array
    {
        return ['value' => 'encrypted'];
    }
}

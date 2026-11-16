<?php

declare(strict_types=1);

namespace Tanaad\Config\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Read model, written only by ConfigProjector (and the seeders).
 *
 * @property string $id
 * @property string $site_id
 * @property string $name
 * @property int $seq
 */
final class Zone extends Model
{
    use HasUuids;

    protected $table = 'zones';

    protected $guarded = [];

    protected $casts = ['site_id' => 'string', 'seq' => 'int', 'deleted_at' => 'immutable_datetime'];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }
}

<?php

declare(strict_types=1);

namespace Tanaad\Media\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $hash
 * @property string $bucket
 * @property string $mime
 * @property int $size
 */
final class Media extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'media';

    protected $guarded = [];

    protected $keyType = 'string';

    public $incrementing = false;

    /** Storage path: content-addressed, fanned out by the first two hex chars. */
    public function path(): string
    {
        return substr($this->hash, 0, 2).'/'.$this->hash;
    }
}

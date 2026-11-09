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
 * @property ?string $site_id
 * @property string $list
 * @property string $label
 * @property ?float $value
 * @property ?string $unit
 * @property ?string $color
 * @property ?string $parent_id
 * @property bool $active
 * @property int $sort_order
 * @property bool $system
 * @property bool $example
 * @property ?string $code
 * @property int $seq
 */
final class RefItem extends Model
{
    use HasUuids;

    protected $table = 'ref_items';

    protected $guarded = [];

    protected $casts = ['value' => 'float', 'active' => 'bool', 'sort_order' => 'int', 'system' => 'bool', 'example' => 'bool', 'seq' => 'int', 'deleted_at' => 'immutable_datetime'];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }
}

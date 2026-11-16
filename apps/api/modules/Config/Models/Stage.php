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
 * @property string $code
 * @property string $name
 * @property int $sequence
 * @property string $input_unit
 * @property string $output_unit
 * @property ?string $produces_label
 * @property int $seq
 */
final class Stage extends Model
{
    use HasUuids;

    protected $table = 'stages';

    protected $guarded = [];

    protected $casts = ['sequence' => 'int', 'seq' => 'int', 'deleted_at' => 'immutable_datetime'];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }
}

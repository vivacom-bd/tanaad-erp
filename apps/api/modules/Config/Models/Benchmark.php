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
 * @property string $machine_id
 * @property string $product_id
 * @property float $units_per_shift
 * @property int $seq
 */
final class Benchmark extends Model
{
    use HasUuids;

    protected $table = 'benchmarks';

    protected $guarded = [];

    protected $casts = ['units_per_shift' => 'float', 'seq' => 'int', 'deleted_at' => 'immutable_datetime'];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }
}

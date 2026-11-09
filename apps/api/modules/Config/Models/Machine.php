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
 * @property ?string $code_key
 * @property string $site_id
 * @property string $code
 * @property string $kind
 * @property string $stage_code
 * @property string $status
 * @property ?string $make
 * @property ?string $model
 * @property ?\Carbon\CarbonImmutable $commissioned
 * @property ?float $capacity_kg_per_day
 * @property int $seq
 */
final class Machine extends Model
{
    use HasUuids;

    protected $table = 'machines';

    protected $guarded = [];

    protected $casts = ['commissioned' => 'immutable_date', 'capacity_kg_per_day' => 'float', 'seq' => 'int', 'deleted_at' => 'immutable_datetime'];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }
}

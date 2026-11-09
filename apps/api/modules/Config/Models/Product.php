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
 * @property ?string $code
 * @property string $site_id
 * @property string $internal_name
 * @property ?string $commercial_name
 * @property string $kind
 * @property int $bundle_pieces
 * @property float $std_bundle_weight_kg
 * @property float $weight_tolerance_pct
 * @property string $weight_status
 * @property ?string $colour
 * @property ?string $unit
 * @property ?int $thickness_micron
 * @property bool $active
 * @property \Illuminate\Database\Eloquent\Collection<int, Price> $prices
 * @property int $seq
 */
final class Product extends Model
{
    use HasUuids;

    protected $table = 'products';

    protected $guarded = [];

    protected $casts = ['bundle_pieces' => 'int', 'std_bundle_weight_kg' => 'float', 'weight_tolerance_pct' => 'float', 'thickness_micron' => 'int', 'active' => 'bool', 'seq' => 'int', 'deleted_at' => 'immutable_datetime'];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<Price, $this> */
    public function prices(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Price::class)->orderBy('effective_from')->orderBy('channel');
    }
}

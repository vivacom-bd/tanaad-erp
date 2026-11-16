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
 * @property \Carbon\CarbonImmutable $day
 * @property string $base
 * @property string $quote
 * @property float $rate
 * @property int $seq
 */
final class FxRate extends Model
{
    use HasUuids;

    protected $table = 'fx_rates';

    protected $guarded = [];

    protected $casts = ['day' => 'immutable_date', 'rate' => 'float', 'seq' => 'int', 'deleted_at' => 'immutable_datetime'];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }
}

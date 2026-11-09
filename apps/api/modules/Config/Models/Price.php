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
 * @property string $product_id
 * @property string $channel
 * @property string $currency
 * @property float $amount
 * @property \Carbon\CarbonImmutable $effective_from
 * @property int $seq
 */
final class Price extends Model
{
    use HasUuids;

    protected $table = 'prices';

    protected $guarded = [];

    protected $casts = ['amount' => 'float', 'effective_from' => 'immutable_date', 'seq' => 'int', 'deleted_at' => 'immutable_datetime'];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }
}

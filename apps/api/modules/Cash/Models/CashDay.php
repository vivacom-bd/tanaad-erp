<?php

declare(strict_types=1);

namespace Tanaad\Cash\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Read model, written only by CashProjector.
 *
 * @property string $id
 * @property string $site_id
 * @property string $day
 * @property string $currency
 * @property string $opening
 * @property string $status
 * @property string|null $expected_closing
 * @property string|null $counted_closing
 * @property string|null $variance
 * @property array<string, int|float>|null $denominations
 * @property string|null $signed_by
 * @property \Carbon\CarbonImmutable|null $closed_at
 * @property string|null $reopen_reason
 * @property int $seq
 */
final class CashDay extends Model
{
    protected $table = 'cash_days';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = ['day' => 'date:Y-m-d', 'denominations' => 'array', 'closed_at' => 'immutable_datetime', 'seq' => 'int'];

    public function dayString(): string
    {
        return substr((string) $this->getRawOriginal('day'), 0, 10);
    }
}

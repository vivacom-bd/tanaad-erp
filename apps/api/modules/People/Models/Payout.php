<?php

declare(strict_types=1);

namespace Tanaad\People\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Read model, written only by SettlementProjector.
 *
 * @property string $id
 * @property string $site_id
 * @property string $settlement_run_id
 * @property string $settlement_line_id
 * @property string $person_id
 * @property string $amount
 * @property string $method
 * @property string|null $reference
 * @property string $paid_at
 * @property int $seq
 */
final class Payout extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'payouts';

    protected $guarded = [];

    protected $casts = ['seq' => 'int', 'deleted_at' => 'immutable_datetime'];
}

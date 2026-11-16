<?php

declare(strict_types=1);

namespace Tanaad\People\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Read model, written only by SettlementProjector.
 *
 * @property string $id
 * @property string $site_id
 * @property string $kind
 * @property string $period_from
 * @property string $period_to
 * @property string $currency
 * @property string $status
 * @property ?string $created_by
 * @property ?string $approved_by
 * @property string $total_net
 * @property int $seq
 */
final class SettlementRun extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'settlement_runs';

    protected $guarded = [];

    protected $casts = ['seq' => 'int', 'deleted_at' => 'immutable_datetime'];
}

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
 * @property string $person_id
 * @property string $engagement_id
 * @property string $period_from
 * @property string $period_to
 * @property string $net
 * @property string $status
 * @property ?string $payout_id
 * @property int $seq
 */
final class SettlementLine extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'settlement_lines';

    protected $guarded = [];

    protected $casts = ['units' => 'float', 'overtime_hours' => 'float', 'deductions' => 'array', 'seq' => 'int', 'deleted_at' => 'immutable_datetime'];
}

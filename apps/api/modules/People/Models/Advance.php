<?php

declare(strict_types=1);

namespace Tanaad\People\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Read model, written only by SettlementProjector.
 *
 * @property string $id
 * @property string $site_id
 * @property string $person_id
 * @property string $amount
 * @property string $deducted
 * @property string $balance
 * @property string $reason
 * @property string $given_on
 * @property int $seq
 */
final class Advance extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'advances';

    protected $guarded = [];

    protected $casts = ['seq' => 'int', 'deleted_at' => 'immutable_datetime'];
}

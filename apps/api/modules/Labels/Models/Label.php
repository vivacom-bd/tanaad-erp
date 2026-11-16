<?php

declare(strict_types=1);

namespace Tanaad\Labels\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Read model, written only by LabelsProjector.
 *
 * @property string $id
 * @property string $site_id
 * @property string $code
 * @property string $kind
 * @property string|null $product_id
 * @property string $stage_code
 * @property string|null $machine_id
 * @property string|null $operator_id
 * @property string|null $shift_kind
 * @property string $qty_units
 * @property string $qty_kg
 * @property string $status
 * @property string|null $zone_id
 * @property list<string> $parent_ids
 * @property string|null $colour
 * @property string|null $grade
 * @property string|null $consumed_by_stage
 * @property string|null $consumed_ref
 * @property string $event_id
 * @property \Carbon\CarbonImmutable $at
 */
final class Label extends Model
{
    protected $table = 'labels';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = ['parent_ids' => 'array', 'at' => 'immutable_datetime', 'seq' => 'int'];

    public function dayString(): string
    {
        return substr((string) $this->getRawOriginal('day'), 0, 10);
    }
}

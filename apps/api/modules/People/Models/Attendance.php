<?php

declare(strict_types=1);

namespace Tanaad\People\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Read model, written only by PeopleProjector.
 *
 * @property string $id
 * @property string $site_id
 * @property string $person_id
 * @property string $day
 * @property string $shift_kind
 * @property string $shift_id
 * @property string $status
 * @property float $overtime_hours
 * @property bool $cross_site
 * @property ?string $marked_by
 * @property string $marked_at
 * @property string $event_id
 * @property int $seq
 */
final class Attendance extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'attendance';

    protected $guarded = [];

    protected $casts = ['overtime_hours' => 'float', 'cross_site' => 'bool', 'seq' => 'int', 'deleted_at' => 'immutable_datetime'];
}

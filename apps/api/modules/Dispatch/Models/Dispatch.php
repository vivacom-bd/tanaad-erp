<?php

declare(strict_types=1);

namespace Tanaad\Dispatch\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Read model, written only by DispatchProjector.
 *
 * @property string $id
 * @property string $site_id
 * @property string $order_ref
 * @property string $client_name
 * @property string $lines
 * @property int $ordered_bundles
 * @property string $expected_kg
 * @property string $driver
 * @property string $vehicle
 * @property string $gate_status
 * @property string|null $gate_kg
 * @property string|null $variance_pct
 * @property string|null $weighed_by
 * @property string|null $delivery_note_no
 * @property string|null $delivered_at
 * @property string|null $gate_reasons
 * @property string|null $override_by
 * @property string|null $override_reason
 * @property string $at
 * @property string|null $gate_passed_at
 * @property string|null $received_by
 * @property int|null $bundles_received
 * @property int|null $discrepancy
 * @property string|null $signature_hash
 * @property string|null $photo_hash
 */
final class Dispatch extends Model
{
    protected $table = 'dispatches';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = ['ordered_bundles' => 'int'];
}

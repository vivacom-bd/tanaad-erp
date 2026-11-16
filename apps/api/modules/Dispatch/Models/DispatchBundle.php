<?php

declare(strict_types=1);

namespace Tanaad\Dispatch\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Read model, written only by DispatchProjector.
 *
 * @property int $id
 * @property string $dispatch_id
 * @property string $label_id
 * @property string $code
 * @property string|null $product_id
 * @property string|null $scanned_by
 */
final class DispatchBundle extends Model
{
    protected $table = 'dispatch_bundles';

    public $timestamps = false;

    protected $guarded = [];
}

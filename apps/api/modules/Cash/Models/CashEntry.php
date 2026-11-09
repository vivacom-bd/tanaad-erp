<?php

declare(strict_types=1);

namespace Tanaad\Cash\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Read model, written only by CashProjector. A corrected entry keeps its row and gets
 * superseded_by; the correction is a new row with corrects_entry_id.
 *
 * @property string $id
 * @property string $site_id
 * @property string $cash_day_id
 * @property string $direction
 * @property string $category
 * @property string|null $subcategory
 * @property string $amount
 * @property string $currency
 * @property string|null $fx_reference
 * @property string|null $machine_id
 * @property string|null $product_id
 * @property string|null $payee
 * @property string|null $note
 * @property bool $has_receipt_photo
 * @property string|null $payout_id
 * @property string|null $corrects_entry_id
 * @property string|null $superseded_by
 * @property string $event_id
 * @property string|null $recorded_by
 * @property \Carbon\CarbonImmutable $at
 * @property int $seq
 */
final class CashEntry extends Model
{
    protected $table = 'cash_entries';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = ['day' => 'date:Y-m-d', 'has_receipt_photo' => 'bool', 'at' => 'immutable_datetime', 'seq' => 'int'];

    public function dayString(): string
    {
        return substr((string) $this->getRawOriginal('day'), 0, 10);
    }
}

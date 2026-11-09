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
 * @property string $type
 * @property string $start_date
 * @property ?string $end_date
 * @property ?int $term_days
 * @property string $rate_basis
 * @property float $rate
 * @property string $currency
 * @property string $settlement_cycle
 * @property string $status
 * @property ?string $renews_engagement_id
 * @property ?string $end_reason
 * @property int $seq
 */
final class Engagement extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'engagements';

    protected $guarded = [];

    protected $casts = ['rate' => 'float', 'term_days' => 'int', 'seq' => 'int', 'deleted_at' => 'immutable_datetime'];
}

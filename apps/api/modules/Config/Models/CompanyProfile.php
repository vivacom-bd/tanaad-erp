<?php

declare(strict_types=1);

namespace Tanaad\Config\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Read model, written only by ConfigProjector (and the seeders).
 *
 * @property string $id
 * @property string $site_id
 * @property string $address
 * @property string $phone
 * @property string $email
 * @property string $tin
 * @property string $bank_details
 * @property string $mobile_money
 * @property float $vat_rate_pct
 * @property bool $efris_connected
 * @property int $seq
 */
final class CompanyProfile extends Model
{
    use HasUuids;

    protected $table = 'company_profiles';

    protected $guarded = [];

    protected $casts = ['vat_rate_pct' => 'float', 'efris_connected' => 'bool', 'seq' => 'int', 'deleted_at' => 'immutable_datetime'];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }
}

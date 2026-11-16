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
 * @property float $gate_tolerance_pct
 * @property float $mass_gap_pct
 * @property float $cash_variance
 * @property float $benchmark_pct
 * @property float $expense_approval_limit
 * @property float $archive_after_absent_days
 * @property float $contract_warning_days
 * @property float $standard_day_hours
 * @property float $month_working_days
 * @property float $downtime_reason_after_min
 * @property float $material_cost_per_kg
 * @property int $seq
 */
final class SiteSetting extends Model
{
    use HasUuids;

    protected $table = 'site_settings';

    protected $guarded = [];

    protected $casts = ['gate_tolerance_pct' => 'float', 'mass_gap_pct' => 'float', 'cash_variance' => 'float', 'benchmark_pct' => 'float', 'expense_approval_limit' => 'float', 'archive_after_absent_days' => 'float', 'contract_warning_days' => 'float', 'standard_day_hours' => 'float', 'month_working_days' => 'float', 'downtime_reason_after_min' => 'float', 'material_cost_per_kg' => 'float', 'seq' => 'int', 'deleted_at' => 'immutable_datetime'];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** camelCase key (prototype SiteSettings) => column */
    public const array FIELDS = [
        'gateTolerancePct' => 'gate_tolerance_pct',
        'massGapPct' => 'mass_gap_pct',
        'cashVariance' => 'cash_variance',
        'benchmarkPct' => 'benchmark_pct',
        'expenseApprovalLimit' => 'expense_approval_limit',
        'archiveAfterAbsentDays' => 'archive_after_absent_days',
        'contractWarningDays' => 'contract_warning_days',
        'standardDayHours' => 'standard_day_hours',
        'monthWorkingDays' => 'month_working_days',
        'downtimeReasonAfterMin' => 'downtime_reason_after_min',
        'materialCostPerKg' => 'material_cost_per_kg',
    ];
}

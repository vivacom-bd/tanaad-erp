<?php

declare(strict_types=1);

namespace Tanaad\Group\Support;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\ConnectionInterface;

/**
 * Exception vocabulary (packages/domain ExceptionKind) and the per-site thresholds the
 * policies use. Thresholds are read from Config's site_settings table by column, never
 * through Config classes (modules do not import each other).
 */
final class ExceptionRules
{
    public const array KINDS = [
        'gate_variance', 'mass_gap', 'cash_variance', 'below_benchmark', 'pod_discrepancy',
        'credit_over_limit', 'ghost_worker', 'contract_expiring', 'low_stock', 'not_logged',
    ];

    /** Kinds the system (policies, scheduler) raises with no actor. */
    public const array SYSTEM_KINDS = ['cash_variance', 'ghost_worker', 'contract_expiring'];

    public const array SEVERITIES = ['info', 'warning', 'critical'];

    /** decision → resulting status */
    public const array DECISIONS = ['approve' => 'approved', 'reject' => 'rejected', 'acknowledge' => 'acknowledged', 'hold' => 'held'];

    /** Statuses of an exception that is still awaiting a final decision. */
    public const array ACTIVE_STATUSES = ['open', 'held'];

    /** Owner roles that only group roles may decide. */
    public const array GROUP_OWNERS = ['ceo', 'group_admin'];

    /** Days before end_date a contract counts as expiring; contractState() default in packages/domain. */
    public const int DEFAULT_CONTRACT_WARNING_DAYS = 3;

    public function __construct(private readonly ConnectionInterface $db, private readonly Config $config) {}

    /**
     * Absolute cash variance tolerated at the site (currency units), from site_settings.cash_variance.
     * With no settings row: tanaad.group.cash_variance_tolerance, default 0 (any variance raises).
     */
    public function cashTolerance(?string $siteId): float
    {
        $value = $siteId === null ? null : $this->db->table('site_settings')->where('site_id', $siteId)->whereNull('deleted_at')->value('cash_variance');

        return is_numeric($value) ? abs((float) $value) : (float) $this->config->get('tanaad.group.cash_variance_tolerance', 0);
    }

    /** site_settings.contract_warning_days; a missing row or a value ≤ 0 falls back to 3. */
    public function contractWarningDays(?string $siteId): int
    {
        $value = $siteId === null ? null : $this->db->table('site_settings')->where('site_id', $siteId)->whereNull('deleted_at')->value('contract_warning_days');

        return is_numeric($value) && (int) $value > 0 ? (int) $value : self::DEFAULT_CONTRACT_WARNING_DAYS;
    }

    public function timezone(): string
    {
        return (string) $this->config->get('tanaad.timezone', 'UTC');
    }
}

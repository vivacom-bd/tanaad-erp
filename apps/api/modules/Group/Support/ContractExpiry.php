<?php

declare(strict_types=1);

namespace Tanaad\Group\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Tanaad\Group\Commands\RaiseException;
use Tanaad\Shared\Ledger\CommandContext;

/** contractState() from packages/domain/src/arithmetic.ts, and the command it leads to. */
final class ContractExpiry
{
    /** @return 'open'|'expiring'|'expired' */
    public static function state(string $endDate, string $today, int $warningDays): string
    {
        $days = self::daysRemaining($endDate, $today);

        return $days < 0 ? 'expired' : ($days <= $warningDays ? 'expiring' : 'open');
    }

    public static function daysRemaining(string $endDate, string $today): int
    {
        return (int) CarbonImmutable::parse($today)->diffInDays(CarbonImmutable::parse(substr($endDate, 0, 10)), false);
    }

    public static function raise(string $siteId, string $engagementId, string $personId, string $endDate, string $today, int $warningDays, ConnectionInterface $db, CarbonImmutable $at): ?RaiseException
    {
        $end = substr($endDate, 0, 10);
        $state = self::state($end, $today, $warningDays);
        if ($state === 'open') {
            return null;
        }
        $days = self::daysRemaining($end, $today);
        $name = $personId === '' ? null : $db->table('people')->where('id', $personId)->value('full_name');
        $who = is_string($name) ? $name : 'Contract';
        $when = match (true) {
            $days < 0 => 'ended on '.$end,
            $days === 0 => 'ends today',
            $days === 1 => 'ends tomorrow',
            default => "ends in {$days} days",
        };

        return new RaiseException(new CommandContext($siteId, null, null, $at), [
            'kind' => 'contract_expiring', 'severity' => $state === 'expired' ? 'warning' : 'info', 'owner_role' => 'site_manager',
            'title' => "{$who}: contract {$when}",
            'detail' => $state === 'expired' ? 'The contract end date has passed. End or renew the engagement.' : 'Renew the contract or let it end.',
            'subject_ref' => $engagementId,
            'facts' => ['engagement_id' => $engagementId, 'person_id' => $personId, 'end_date' => $end, 'days_remaining' => $days, 'warning_days' => $warningDays],
        ]);
    }
}

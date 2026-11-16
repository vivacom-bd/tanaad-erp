<?php

declare(strict_types=1);

namespace Tanaad\Group\Support;

use Carbon\CarbonImmutable;
use Tanaad\Group\Commands\RaiseException;
use Tanaad\Shared\Ledger\CommandContext;

/** The not_logged exception, shared by NotLoggedPolicy and the production:shift-unlogged scheduler. One per machine and shift. */
final class NotLogged
{
    public static function subject(string $machineId, string $shiftId): string
    {
        return "{$machineId}|{$shiftId}";
    }

    public static function raise(string $siteId, string $machineId, string $shiftId, string $day, CarbonImmutable $at, string $why): RaiseException
    {
        return new RaiseException(new CommandContext($siteId, null, null, $at), [
            'kind' => 'not_logged', 'severity' => 'warning', 'owner_role' => 'supervisor',
            'title' => 'Machine not logged',
            'detail' => $why === 'closed' ? "Shift {$shiftId} was closed with no production entry for this machine." : "No production entry for this machine yet in shift {$shiftId}.",
            'subject_ref' => self::subject($machineId, $shiftId),
            'facts' => ['machine_id' => $machineId, 'shift_id' => $shiftId, 'day' => $day],
        ]);
    }
}

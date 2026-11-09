<?php

declare(strict_types=1);

namespace Tanaad\People\Handlers;

use Tanaad\Config\Support\Payload;
use Tanaad\People\Commands\CancelSettlementRun;
use Tanaad\People\Models\SettlementLine;
use Tanaad\People\Models\SettlementRun;
use Tanaad\People\Support\Sites;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;

/**
 * A created or approved run can be cancelled while nobody has been paid from it. A run
 * that is fully paid, already cancelled, or partly paid (a payout exists and the cash
 * book has it) is a state conflict: money already left, so cancelling would lie.
 */
final class CancelSettlementRunHandler implements Handler
{
    public function handle(Command $command): array
    {
        if (! $command instanceof CancelSettlementRun) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $site = Sites::of($command);
        $p = new Payload($command->payload);
        $runId = $p->uuid('settlement_run_id');
        $reason = $p->optStr('reason');
        $p->check();
        $run = SettlementRun::query()->where(['id' => $runId, 'site_id' => $site->id])->first()
            ?? throw Rejection::notFound('Settlement run', $runId);
        if ($run->status === 'paid') {
            throw Rejection::stateConflict('This run is fully paid and cannot be cancelled.');
        }
        if ($run->status === 'cancelled') {
            throw Rejection::stateConflict('This run is already cancelled.');
        }
        if (SettlementLine::query()->where(['settlement_run_id' => $runId, 'status' => 'paid'])->exists()) {
            throw Rejection::stateConflict('Part of this run has been paid out, so it cannot be cancelled.');
        }

        return [Event::from($command->context(), 'people.settlement_cancelled', ['settlement_run_id' => $runId, 'reason' => $reason])];
    }
}

<?php

declare(strict_types=1);

namespace Tanaad\People\Handlers;

use Tanaad\Config\Support\Payload;
use Tanaad\People\Commands\ApproveSettlement;
use Tanaad\People\Models\SettlementRun;
use Tanaad\People\Support\Sites;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;

/** A created run becomes approved, by someone other than the person who created it (ADR-006). */
final class ApproveSettlementHandler implements Handler
{
    public function handle(Command $command): array
    {
        if (! $command instanceof ApproveSettlement) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $site = Sites::of($command);
        $p = new Payload($command->payload);
        $runId = $p->uuid('settlement_run_id');
        $p->check();
        $run = SettlementRun::query()->where(['id' => $runId, 'site_id' => $site->id])->first()
            ?? throw Rejection::notFound('Settlement run', $runId);
        if ($run->status !== 'created') {
            throw Rejection::stateConflict("This run is already {$run->status}.");
        }
        if ($run->created_by !== null && $run->created_by === $command->context()->actorId) {
            throw Rejection::forbidden('The person who created a settlement run may not approve it.');
        }

        return [Event::from($command->context(), 'people.settlement_approved', ['settlement_run_id' => $runId])];
    }
}

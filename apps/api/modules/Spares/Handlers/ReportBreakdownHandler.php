<?php

declare(strict_types=1);

namespace Tanaad\Spares\Handlers;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Tanaad\Config\Support\Payload;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;
use Tanaad\Spares\Commands\ReportBreakdown;
use Tanaad\Spares\Support\Spares;

/**
 * A machine stops. One open breakdown per machine: the machine row is locked so two reports
 * of the same stop cannot both open. Config owns machines; "down" lives in the breakdowns read model.
 */
final class ReportBreakdownHandler implements Handler
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function handle(Command $command): array
    {
        if (! $command instanceof ReportBreakdown) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $ctx = $command->context();
        $siteId = $ctx->siteId ?? throw Rejection::invalidPayload(['site_id' => 'required']);
        $p = new Payload($command->payload);
        $id = $p->optUuid('breakdown_id') ?? (string) Str::uuid7();
        $machineId = $p->uuid('machine_id');
        $cause = $p->str('cause', 500);
        $startedAt = Spares::instant($p, 'started_at', $ctx->occurredAt);
        $p->check();

        $machine = Spares::lockedMachine($this->db, $siteId, $machineId);
        if ($this->db->table('breakdowns')->where('id', $id)->exists()) {
            throw Rejection::duplicateFact("Breakdown {$id} is already recorded.");
        }
        if ($this->db->table('breakdowns')->where(['machine_id' => $machineId])->whereNull('resolved_at')->whereNull('deleted_at')->exists()) {
            throw Rejection::stateConflict("{$machine->code} is already reported down.");
        }

        return [Event::from($ctx, 'spares.breakdown_reported', [
            'breakdown_id' => $id, 'machine_id' => $machineId, 'machine_code' => (string) $machine->code,
            'cause' => $cause, 'started_at' => $startedAt->format('Y-m-d\TH:i:s.u\Z'),
        ])];
    }
}

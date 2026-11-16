<?php

declare(strict_types=1);

namespace Tanaad\Production\Handlers;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Tanaad\Config\Support\Payload;
use Tanaad\Production\Commands\WeighWaste;
use Tanaad\Production\Support\ProductionBook;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;
use Tanaad\Shared\Money\Rounding;

/**
 * {waste_id?, day, stage_code, waste_kind, kg, reason?, machine_id?}. waste_kind is an active item
 * (code or id) of the waste_source ref list. The event carries the day's mass balance including this weighing.
 */
final class WeighWasteHandler implements Handler
{
    public function __construct(private readonly ProductionBook $book, private readonly ConnectionInterface $db) {}

    public function handle(Command $command): array
    {
        if (! $command instanceof WeighWaste) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $ctx = $command->context();
        $siteId = $ctx->siteId ?? throw Rejection::invalidPayload(['site_id' => 'required']);
        $p = new Payload($command->payload);
        [$day] = ShiftInput::read($p, $this->book, $siteId, kindRequired: false);
        $wasteId = $p->optUuid('waste_id') ?? (string) Str::uuid7();
        $stage = $p->str('stage_code', 32);
        $kindRef = $p->str('waste_kind', 64);
        $kg = Rounding::kg($p->num('kg', positive: true));
        if ($kg > 1e7) {
            $p->error('kg', 'must be at most 10000000');
        }
        $reason = $p->optStr('reason', 500);
        $machineId = $p->optUuid('machine_id');
        $p->check();

        if (! $this->book->stageExists($siteId, $stage)) {
            throw Rejection::invalidPayload(['stage_code' => 'no such stage at this site']);
        }
        $kind = $this->book->wasteKind($siteId, $kindRef) ?? throw Rejection::invalidPayload(['waste_kind' => 'not an active item of the '.ProductionBook::WASTE_LIST.' list']);
        if ($machineId !== null && $this->book->machine($siteId, $machineId) === null) {
            throw Rejection::invalidPayload(['machine_id' => 'no such machine at this site']);
        }
        if ($this->db->table('waste_weighings')->where('id', $wasteId)->exists()) {
            throw Rejection::duplicateFact("Waste weighing {$wasteId} already exists.");
        }

        return [Event::from($ctx, 'production.waste_weighed', [
            'waste_id' => $wasteId, 'day' => $day, 'stage_code' => $stage, 'waste_kind' => $kind, 'kg' => $kg,
            'reason' => $reason, 'machine_id' => $machineId, 'mass_balance' => $this->book->massBalance($siteId, $day, $kg),
        ])];
    }
}

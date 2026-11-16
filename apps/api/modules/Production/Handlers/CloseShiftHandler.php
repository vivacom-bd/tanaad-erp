<?php

declare(strict_types=1);

namespace Tanaad\Production\Handlers;

use Illuminate\Database\ConnectionInterface;
use Tanaad\Config\Support\Payload;
use Tanaad\Production\Commands\CloseShift;
use Tanaad\Production\Support\ProductionBook;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;

/** {day, kind}. The event carries the wall machines still unlogged and the day's mass balance, for the Group policies. */
final class CloseShiftHandler implements Handler
{
    public function __construct(private readonly ProductionBook $book, private readonly ConnectionInterface $db) {}

    public function handle(Command $command): array
    {
        if (! $command instanceof CloseShift) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $ctx = $command->context();
        $siteId = $ctx->siteId ?? throw Rejection::invalidPayload(['site_id' => 'required']);
        $p = new Payload(ShiftInput::flatten($command->payload));
        [$day, $kind] = ShiftInput::read($p, $this->book, $siteId);
        $p->check();
        $shiftId = $this->book->shiftId($siteId, $day, $kind);
        if ($this->db->table('production_shifts_closed')->where('id', $shiftId)->exists()) {
            throw Rejection::stateConflict("Shift {$shiftId} is already closed.");
        }

        return [Event::from($ctx, 'production.shift_closed', [
            'shift_id' => $shiftId, 'day' => $day, 'shift_kind' => $kind,
            'unlogged_machine_ids' => $this->book->unlogged($siteId, $shiftId),
            'mass_balance' => $this->book->massBalance($siteId, $day),
        ])];
    }
}

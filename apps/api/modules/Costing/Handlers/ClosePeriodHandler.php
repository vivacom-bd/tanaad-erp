<?php

declare(strict_types=1);

namespace Tanaad\Costing\Handlers;

use Illuminate\Support\Facades\DB;
use Tanaad\Config\Support\Payload;
use Tanaad\Costing\Commands\ClosePeriod;
use Tanaad\Costing\Support\CostBook;
use Tanaad\Costing\Support\Period;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;

/**
 * Freezes the P&L and unit costs of a period from its cash-closed days. The whole figure set
 * travels in the event (snapshot), so history never moves when later data or settings change.
 * A closed period must be reopened (with a reason) before it can be closed again.
 */
final class ClosePeriodHandler implements Handler
{
    public function handle(Command $command): array
    {
        if (! $command instanceof ClosePeriod) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $ctx = $command->context();
        $siteId = $ctx->siteId ?? throw Rejection::invalidPayload(['site_id' => 'required']);
        $p = new Payload($command->payload);
        $period = Period::read($p, $command->payload);
        if ($period !== null && $period->to > Period::today()) {
            $p->error('to', 'a period cannot end in the future');
        }
        $p->check();
        $period = $period ?? throw new \LogicException('Payload::check passed without a period.');

        // Serialise closes of one site so two concurrent closes cannot both pass the status check.
        DB::table('sites')->where('id', $siteId)->lockForUpdate()->first() ?? throw Rejection::notFound('Site', $siteId);
        $id = CostBook::periodId($siteId, $period->from, $period->to);
        $existing = DB::table('costing_periods')->where('id', $id)->lockForUpdate()->first(['status', 'version']);
        if ($existing?->status === 'closed') {
            throw Rejection::stateConflict("The period {$period->label} is already closed; reopen it with a reason first.");
        }

        return [Event::from($ctx, 'costing.period_closed', [
            'period_id' => $id, 'label' => $period->label, 'from' => $period->from, 'to' => $period->to,
            'version' => $existing === null ? 1 : (int) $existing->version + 1,
            'snapshot' => CostBook::compute($siteId, $period->from, $period->to),
        ])];
    }
}

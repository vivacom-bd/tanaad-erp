<?php

declare(strict_types=1);

namespace Tanaad\Costing\Handlers;

use Illuminate\Support\Facades\DB;
use Tanaad\Config\Support\Payload;
use Tanaad\Costing\Commands\ReopenPeriod;
use Tanaad\Costing\Support\CostBook;
use Tanaad\Costing\Support\Period;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;

/** Reopens a closed period so it can be closed again; the earlier snapshot stays in the ledger. */
final class ReopenPeriodHandler implements Handler
{
    public function handle(Command $command): array
    {
        if (! $command instanceof ReopenPeriod) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $ctx = $command->context();
        $siteId = $ctx->siteId ?? throw Rejection::invalidPayload(['site_id' => 'required']);
        $p = new Payload($command->payload);
        $period = Period::read($p, $command->payload);
        $reason = $p->str('reason', 1000);
        $p->check();
        $period = $period ?? throw new \LogicException('Payload::check passed without a period.');

        $id = CostBook::periodId($siteId, $period->from, $period->to);
        $row = DB::table('costing_periods')->where('id', $id)->lockForUpdate()->first(['status']);
        if ($row === null) {
            throw Rejection::notFound('Costing period', $period->label);
        }
        if ($row->status !== 'closed') {
            throw Rejection::stateConflict("The period {$period->label} is not closed.");
        }

        return [Event::from($ctx, 'costing.period_reopened', [
            'period_id' => $id, 'label' => $period->label, 'from' => $period->from, 'to' => $period->to, 'reason' => $reason,
        ], reason: $reason)];
    }
}

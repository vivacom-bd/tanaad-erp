<?php

declare(strict_types=1);

namespace Tanaad\Spares\Handlers;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Tanaad\Config\Support\Payload;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;
use Tanaad\Shared\Money\Rounding;
use Tanaad\Spares\Commands\ResolveBreakdown;
use Tanaad\Spares\Support\Spares;

/**
 * The machine is back. The breakdown row is locked (IssuePart locks it too), the spares cost is the
 * sum of the issues linked to it, and the duration in whole minutes: both travel in the event.
 */
final class ResolveBreakdownHandler implements Handler
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function handle(Command $command): array
    {
        if (! $command instanceof ResolveBreakdown) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $ctx = $command->context();
        $siteId = $ctx->siteId ?? throw Rejection::invalidPayload(['site_id' => 'required']);
        $p = new Payload($command->payload);
        $id = $p->uuid('breakdown_id');
        $resolvedAt = Spares::instant($p, 'resolved_at', $ctx->occurredAt);
        $p->check();

        $b = $this->db->table('breakdowns')->where(['id' => $id, 'site_id' => $siteId])->whereNull('deleted_at')->lockForUpdate()->first();
        if ($b === null) {
            throw Rejection::notFound('Breakdown', $id);
        }
        if ($b->resolved_at !== null) {
            throw Rejection::stateConflict('That breakdown is already resolved.');
        }
        $started = CarbonImmutable::parse((string) $b->started_at, 'UTC');
        if ($resolvedAt->lessThan($started)) {
            throw Rejection::invalidPayload(['resolved_at' => 'must not be before the breakdown started']);
        }
        $cost = $this->db->table('part_issues')->where('breakdown_id', $id)->whereNull('deleted_at')->sum('cost');

        return [Event::from($ctx, 'spares.breakdown_resolved', [
            'breakdown_id' => $id, 'machine_id' => (string) $b->machine_id, 'resolved_at' => $resolvedAt->format('Y-m-d\TH:i:s.u\Z'),
            'duration_minutes' => (int) intdiv($resolvedAt->getTimestamp() - $started->getTimestamp(), 60),
            'spares_cost' => Rounding::money((string) $cost),
        ])];
    }
}

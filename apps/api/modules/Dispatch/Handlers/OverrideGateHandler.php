<?php

declare(strict_types=1);

namespace Tanaad\Dispatch\Handlers;

use Tanaad\Config\Support\Payload;
use Tanaad\Dispatch\Commands\OverrideGate;
use Tanaad\Dispatch\Support\DispatchBook;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;

/**
 * Releases a held lorry ({dispatch_id, reason}). DispatchAuthorizer already requires the actor to
 * be the site's Site Manager. Separation of duties is enforced here: the overrider may not be the
 * person who recorded the gate weight nor anyone who scanned a bundle onto this load (403).
 *
 * The prototype re-asked for a PIN; Identity's PIN check is not importable from a module, so the
 * re-authentication is the signed-in Site Manager session that sent the command plus the mandatory
 * reason, both recorded in the event (the audit).
 */
final class OverrideGateHandler implements Handler
{
    public function __construct(private readonly DispatchBook $book) {}

    public function handle(Command $command): array
    {
        if (! $command instanceof OverrideGate) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $ctx = $command->context();
        $siteId = DispatchBook::siteId($ctx->siteId);
        $p = new Payload($command->payload);
        $reason = $p->str('reason', 500);
        if ($reason !== '' && mb_strlen($reason) < 5) {
            $p->error('reason', 'at least 5 characters');
        }
        $dispatch = $this->book->lockDispatch($p, $siteId);
        if ($dispatch->gate_status !== 'hold') {
            throw Rejection::stateConflict("Dispatch is {$dispatch->gate_status}; only a held gate can be overridden.");
        }
        $bundles = $this->book->bundles($dispatch->id);
        $actor = $ctx->actorId;
        if ($actor === null || $actor === $dispatch->weighed_by) {
            throw Rejection::forbidden('The person who recorded the gate weight may not override it.');
        }
        foreach ($bundles as $b) {
            if ($b->scanned_by === $actor) {
                throw Rejection::forbidden('A person who loaded this lorry may not override its gate.');
            }
        }
        $this->book->assertLoadable($bundles);
        $no = $this->book->allocateNote($siteId);
        $facts = [
            'dispatch_id' => $dispatch->id, 'order_ref' => $dispatch->order_ref, 'delivery_note_no' => $no, 'number' => $no,
            'reason' => $reason, 'weighed_by' => $dispatch->weighed_by, 'kg' => $dispatch->gate_kg, 'expected_kg' => $dispatch->expected_kg,
            'variance_pct' => $dispatch->variance_pct === null ? null : (float) $dispatch->variance_pct,
            'scanned_bundles' => count($bundles), 'ordered_bundles' => (int) $dispatch->ordered_bundles,
        ];
        $events = [Event::from($ctx, 'dispatch.gate_overridden', $facts, reason: $reason)];
        foreach (DispatchBook::labelFacts($bundles, $dispatch->id, $no) as $label) {
            $events[] = Event::from($ctx, 'dispatch.label_dispatched', $label, primary: false);
        }

        return $events;
    }
}

<?php

declare(strict_types=1);

namespace Tanaad\Dispatch\Handlers;

use Illuminate\Database\ConnectionInterface;
use Tanaad\Config\Support\Payload;
use Tanaad\Dispatch\Commands\RecordGateWeight;
use Tanaad\Dispatch\Support\DispatchBook;
use Tanaad\Shared\Arithmetic\Arithmetic;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;

/**
 * The gate scale reading ({dispatch_id, kg}). Variance and the pass/hold decision are computed
 * HERE with Arithmetic::gateDecision (contracts/fixtures gateDecision) against the site's
 * gate_tolerance_pct and the server's own scanned count; anything the client sends about the
 * decision is ignored.
 *
 * pass → dispatch.gate_weighed + dispatch.gate_passed (delivery note number allocated now) +
 *        one dispatch.label_dispatched per loaded label.
 * hold → dispatch.gate_weighed + dispatch.gate_held (Group raises gate_variance, critical).
 */
final class RecordGateWeightHandler implements Handler
{
    public function __construct(private readonly ConnectionInterface $db, private readonly DispatchBook $book) {}

    public function handle(Command $command): array
    {
        if (! $command instanceof RecordGateWeight) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $ctx = $command->context();
        $siteId = DispatchBook::siteId($ctx->siteId);
        $p = new Payload($command->payload);
        $kg = $p->num('kg', 0.0, true);
        if ($kg > DispatchBook::MAX_KG) {
            $p->error('kg', 'must be at most 10000000');
        }
        $dispatch = $this->book->lockDispatch($p, $siteId);
        if (! in_array($dispatch->gate_status, DispatchBook::OPEN, true)) {
            throw Rejection::stateConflict("Dispatch is {$dispatch->gate_status}; the gate has already been decided.");
        }
        $tolerance = $this->book->tolerancePct($siteId);
        $bundles = $this->book->bundles($dispatch->id);
        $expected = (float) $dispatch->expected_kg;
        $kgExact = (float) DispatchBook::kg($kg);
        $r = Arithmetic::gateDecision([
            'expectedKg' => $expected, 'actualKg' => $kgExact, 'tolerancePct' => $tolerance,
            'scannedBundles' => count($bundles), 'orderedBundles' => (int) $dispatch->ordered_bundles,
        ]);
        $pass = $r['decision'] === 'pass';
        if ($pass) {
            $this->book->assertLoadable($bundles);
        }
        $no = $pass ? $this->book->allocateNote($siteId) : null;
        $facts = [
            'dispatch_id' => $dispatch->id, 'order_ref' => $dispatch->order_ref, 'client_name' => $dispatch->client_name,
            'kg' => DispatchBook::kg($kgExact), 'expected_kg' => DispatchBook::kg($expected), 'variance_pct' => $r['variancePct'],
            'tolerance_pct' => $tolerance, 'scanned_bundles' => count($bundles), 'ordered_bundles' => (int) $dispatch->ordered_bundles,
            'decision' => $r['decision'], 'reasons' => $r['reasons'],
        ];

        $events = [Event::from($ctx, 'dispatch.gate_weighed', [...$facts, 'delivery_note_no' => $no, 'number' => $no])];
        if (! $pass) {
            $site = $this->db->table('sites')->where('id', $siteId)->first(['code', 'name']);
            $events[] = Event::from($ctx, 'dispatch.gate_held', [...$facts, 'site_name' => $site->name ?? $site->code ?? ''], primary: false);

            return $events;
        }
        /** @var string $no */
        $events[] = Event::from($ctx, 'dispatch.gate_passed', [...$facts, 'delivery_note_no' => $no, 'via' => 'pass'], primary: false);
        foreach (DispatchBook::labelFacts($bundles, $dispatch->id, $no) as $label) {
            $events[] = Event::from($ctx, 'dispatch.label_dispatched', $label, primary: false);
        }

        return $events;
    }
}

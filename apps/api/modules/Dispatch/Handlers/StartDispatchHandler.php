<?php

declare(strict_types=1);

namespace Tanaad\Dispatch\Handlers;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Tanaad\Config\Support\Payload;
use Tanaad\Dispatch\Commands\StartDispatch;
use Tanaad\Dispatch\Support\DispatchBook;
use Tanaad\Shared\Arithmetic\Arithmetic;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;

/**
 * Opens a pick list for an order snapshot (see DispatchServiceProvider for the contract).
 * Expected cargo kg is computed here from the products' std_bundle_weight_kg with
 * Arithmetic::expectedCargoKg; the weights used travel in the payload. No delivery note
 * number is allocated yet: that happens at gate pass.
 */
final class StartDispatchHandler implements Handler
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function handle(Command $command): array
    {
        if (! $command instanceof StartDispatch) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $ctx = $command->context();
        $siteId = DispatchBook::siteId($ctx->siteId);
        $siteCode = (string) ($this->db->table('sites')->where('id', $siteId)->value('code') ?? throw Rejection::notFound('Site', $siteId));
        $p = new Payload($command->payload);
        $id = $p->optUuid('dispatch_id') ?? (string) Str::uuid7();
        $driver = $p->str('driver', 120);
        $vehicle = $p->str('vehicle', 64);
        $raw = $command->payload['order'] ?? null;
        if (! is_array($raw) || array_is_list($raw)) {
            $p->error('order', 'must be an object {order_ref, client_name, lines, site}');
            $p->check();
        }
        /** @var array<string, mixed> $raw */
        $o = new Payload($raw, 'order.', $p);
        $orderRef = $o->str('order_ref', 64);
        $client = $o->str('client_name', 255);
        $site = $o->str('site', 64);
        if ($site !== '' && $site !== $siteCode && $site !== $siteId) {
            $o->error('site', 'must be this site');
        }
        $lines = [];
        foreach ($o->children('lines') as $i => $line) {
            $product = $line->uuid('product_id');
            $bundles = $line->int('bundles', 1);
            if ($bundles > DispatchBook::MAX_BUNDLES) {
                $line->error('bundles', 'must be at most '.DispatchBook::MAX_BUNDLES);
            }
            if ($product !== '' && isset($lines[$product])) {
                $line->error('product_id', 'appears on more than one line');
            }
            $lines[$product] = ['i' => $i, 'product_id' => $product, 'bundles' => $bundles];
        }
        if ($lines === [] && is_array($raw['lines'] ?? null)) {
            $o->error('lines', 'at least one line is required');
        }
        $p->check();

        $weights = $this->db->table('products')->where('site_id', $siteId)->whereNull('deleted_at')->where('active', true)
            ->whereIn('id', array_keys($lines))->pluck('std_bundle_weight_kg', 'id');
        $out = [];
        foreach ($lines as $product => $l) {
            $w = $weights[$product] ?? null;
            if (! is_numeric($w)) {
                $p->error("order.lines.{$l['i']}.product_id", 'is not an active product of this site');

                continue;
            }
            $out[] = ['product_id' => $product, 'bundles' => $l['bundles'], 'std_bundle_weight_kg' => DispatchBook::kg((string) $w)];
        }
        $p->check();

        if ($this->db->table('dispatches')->where('id', $id)->exists()) {
            throw Rejection::duplicateFact("Dispatch {$id} already exists.");
        }
        $open = $this->db->table('dispatches')->where(['site_id' => $siteId, 'order_ref' => $orderRef])
            ->whereIn('gate_status', DispatchBook::OPEN)->lockForUpdate()->value('id');
        if (is_string($open)) {
            throw Rejection::stateConflict("Order {$orderRef} already has an open dispatch {$open}.");
        }
        $expected = Arithmetic::expectedCargoKg(array_map(fn (array $l): array => ['bundles' => $l['bundles'], 'stdBundleWeightKg' => (float) $l['std_bundle_weight_kg']], $out));

        return [Event::from($ctx, 'dispatch.started', [
            'dispatch_id' => $id,
            'order' => ['order_ref' => $orderRef, 'client_name' => $client, 'site' => $siteCode, 'lines' => $out],
            'ordered_bundles' => array_sum(array_column($out, 'bundles')),
            'expected_kg' => DispatchBook::kg($expected),
            'driver' => $driver,
            'vehicle' => $vehicle,
        ])];
    }
}

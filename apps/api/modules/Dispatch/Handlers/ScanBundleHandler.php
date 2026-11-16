<?php

declare(strict_types=1);

namespace Tanaad\Dispatch\Handlers;

use Illuminate\Database\ConnectionInterface;
use Tanaad\Config\Support\Payload;
use Tanaad\Dispatch\Commands\ScanBundle;
use Tanaad\Dispatch\Support\DispatchBook;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;

/**
 * Loads one bundle onto a dispatch by label code ({dispatch_id, code}). The dispatch and the label
 * rows are locked. Labels are read by table name only and are NOT changed here: Labels reacts to
 * dispatch.label_dispatched at gate pass.
 */
final class ScanBundleHandler implements Handler
{
    public function __construct(private readonly ConnectionInterface $db, private readonly DispatchBook $book) {}

    public function handle(Command $command): array
    {
        if (! $command instanceof ScanBundle) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $ctx = $command->context();
        $siteId = DispatchBook::siteId($ctx->siteId);
        $p = new Payload($command->payload);
        $code = $p->str('code', 64);
        $dispatch = $this->book->lockDispatch($p, $siteId);
        if (! in_array($dispatch->gate_status, DispatchBook::OPEN, true)) {
            throw Rejection::stateConflict("Dispatch is {$dispatch->gate_status}; bundles can no longer be loaded.");
        }

        $label = $this->db->table('labels')->where(['site_id' => $siteId, 'code' => $code])->whereNull('deleted_at')->lockForUpdate()->first();
        if ($label === null) {
            $p->error('code', "unknown label {$code} at this site");
            $p->check();
        }
        /** @var object{id: string, code: string, status: string, product_id: ?string} $label */
        if ($this->db->table('dispatch_bundles')->where(['dispatch_id' => $dispatch->id, 'label_id' => $label->id])->exists()) {
            throw Rejection::stateConflict("Already scanned: {$label->code}.");
        }
        if ($label->status !== 'in_stock') {
            throw Rejection::stateConflict("{$label->code} is {$label->status}, not in stock.");
        }
        $other = $this->db->table('dispatch_bundles as b')->join('dispatches as d', 'd.id', '=', 'b.dispatch_id')
            ->where('b.label_id', $label->id)->where('b.dispatch_id', '!=', $dispatch->id)->value('d.id');
        if (is_string($other)) {
            throw Rejection::stateConflict("{$label->code} is already loaded on dispatch {$other}.");
        }
        /** @var list<array{product_id: string, bundles: int}> $lines */
        $lines = json_decode((string) $dispatch->lines, true, 8, JSON_THROW_ON_ERROR);
        $line = null;
        foreach ($lines as $l) {
            if ($l['product_id'] === $label->product_id) {
                $line = $l;
            }
        }
        if ($line === null) {
            $p->error('code', "{$label->code}: its product is not on this order");
            $p->check();
        }
        /** @var array{product_id: string, bundles: int} $line */
        $already = $this->db->table('dispatch_bundles')->where(['dispatch_id' => $dispatch->id, 'product_id' => $label->product_id])->count();
        if ($already >= $line['bundles']) {
            throw Rejection::stateConflict("Order has only {$line['bundles']} bundles of this product.");
        }

        return [Event::from($ctx, 'dispatch.bundle_scanned', [
            'dispatch_id' => $dispatch->id, 'label_id' => $label->id, 'code' => $label->code, 'product_id' => $label->product_id,
        ])];
    }
}

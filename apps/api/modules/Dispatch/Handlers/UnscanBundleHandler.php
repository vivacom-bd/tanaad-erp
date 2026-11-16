<?php

declare(strict_types=1);

namespace Tanaad\Dispatch\Handlers;

use Illuminate\Database\ConnectionInterface;
use Tanaad\Config\Support\Payload;
use Tanaad\Dispatch\Commands\UnscanBundle;
use Tanaad\Dispatch\Support\DispatchBook;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;

/** Takes a scanned bundle back off a dispatch that has not left the gate ({dispatch_id, code}). */
final class UnscanBundleHandler implements Handler
{
    public function __construct(private readonly ConnectionInterface $db, private readonly DispatchBook $book) {}

    public function handle(Command $command): array
    {
        if (! $command instanceof UnscanBundle) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $ctx = $command->context();
        $siteId = DispatchBook::siteId($ctx->siteId);
        $p = new Payload($command->payload);
        $code = $p->str('code', 64);
        $dispatch = $this->book->lockDispatch($p, $siteId);
        if (! in_array($dispatch->gate_status, DispatchBook::OPEN, true)) {
            throw Rejection::stateConflict("Dispatch is {$dispatch->gate_status}; its load can no longer change.");
        }
        $row = $this->db->table('dispatch_bundles')->where(['dispatch_id' => $dispatch->id, 'code' => $code])->lockForUpdate()->first();
        if ($row === null) {
            throw Rejection::stateConflict("{$code} is not loaded on this dispatch.");
        }

        return [Event::from($ctx, 'dispatch.bundle_unscanned', [
            'dispatch_id' => $dispatch->id, 'label_id' => $row->label_id, 'code' => $row->code, 'product_id' => $row->product_id,
        ])];
    }
}

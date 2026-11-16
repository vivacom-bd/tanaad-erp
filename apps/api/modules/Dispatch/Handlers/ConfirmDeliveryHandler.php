<?php

declare(strict_types=1);

namespace Tanaad\Dispatch\Handlers;

use Tanaad\Config\Support\Payload;
use Tanaad\Dispatch\Commands\ConfirmDelivery;
use Tanaad\Dispatch\Support\DispatchBook;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;

/**
 * Proof of delivery ({dispatch_id, received_by, bundles_received, signature_hash, photo_hash?}).
 * Signature and photo are Media hashes (sha256, 64 lowercase hex): the files live in Media;
 * only the format is checked here. discrepancy = bundles_received − bundles scanned at the gate,
 * carried in the payload for PodDiscrepancyPolicy.
 */
final class ConfirmDeliveryHandler implements Handler
{
    public function __construct(private readonly DispatchBook $book) {}

    public function handle(Command $command): array
    {
        if (! $command instanceof ConfirmDelivery) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $ctx = $command->context();
        $siteId = DispatchBook::siteId($ctx->siteId);
        $p = new Payload($command->payload);
        $receivedBy = $p->str('received_by', 120);
        $received = $p->int('bundles_received', 0);
        if ($received > DispatchBook::MAX_BUNDLES) {
            $p->error('bundles_received', 'must be at most '.DispatchBook::MAX_BUNDLES);
        }
        $signature = $this->hash($p, 'signature_hash', true);
        $photo = $this->hash($p, 'photo_hash', false);
        $dispatch = $this->book->lockDispatch($p, $siteId);
        if (! in_array($dispatch->gate_status, DispatchBook::RELEASED, true)) {
            throw Rejection::stateConflict("Dispatch is {$dispatch->gate_status}; it has not passed the gate.");
        }
        if ($dispatch->delivered_at !== null) {
            throw Rejection::stateConflict('Delivery is already confirmed for this dispatch.');
        }
        $scanned = count($this->book->bundles($dispatch->id));

        return [Event::from($ctx, 'dispatch.delivered', [
            'dispatch_id' => $dispatch->id, 'order_ref' => $dispatch->order_ref, 'client_name' => $dispatch->client_name,
            'delivery_note_no' => $dispatch->delivery_note_no, 'received_by' => $receivedBy,
            'bundles_received' => $received, 'bundles_expected' => $scanned, 'discrepancy' => $received - $scanned,
            'signature_hash' => $signature, 'photo_hash' => $photo,
        ])];
    }

    private function hash(Payload $p, string $key, bool $required): ?string
    {
        $v = $p->optStr($key, 64);
        if ($v === null) {
            if ($required) {
                $p->error($key, 'required');
            }

            return null;
        }
        if (preg_match('/^[a-f0-9]{64}$/', $v) !== 1) {
            $p->error($key, 'must be a sha256 media hash (64 lowercase hex)');
        }

        return $v;
    }
}

<?php

declare(strict_types=1);

namespace Tanaad\Purchases\Factories;

use Tanaad\Purchases\Commands\ChangePurchase;
use Tanaad\Purchases\Commands\ReceivePurchase;
use Tanaad\Purchases\Commands\RecordPurchase;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\CommandFactory;
use Tanaad\Shared\Ledger\EventEnvelope;
use Tanaad\Shared\Ledger\Rejection;

/** Offline envelopes → Purchases commands. Validation lives in the handlers. */
final class PurchasesEnvelopeFactory implements CommandFactory
{
    public function fromEnvelope(EventEnvelope $envelope, CommandContext $context): Command
    {
        return match ($envelope->type) {
            'purchases.recorded' => new RecordPurchase($context, $envelope->payload),
            'purchases.changed' => new ChangePurchase($context, $envelope->payload),
            'purchases.received' => new ReceivePurchase($context, $envelope->payload),
            default => throw Rejection::unknownType($envelope->type),
        };
    }
}

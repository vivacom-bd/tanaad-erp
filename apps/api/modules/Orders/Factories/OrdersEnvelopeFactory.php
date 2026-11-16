<?php

declare(strict_types=1);

namespace Tanaad\Orders\Factories;

use Illuminate\Support\Facades\DB;
use Tanaad\Orders\Commands\ApproveOrder;
use Tanaad\Orders\Commands\ConfirmOrderCash;
use Tanaad\Orders\Commands\CreateOrder;
use Tanaad\Orders\Commands\HoldOrder;
use Tanaad\Orders\Commands\IssueInvoice;
use Tanaad\Orders\Commands\RecordPayment;
use Tanaad\Orders\Commands\RegisterClient;
use Tanaad\Orders\Commands\UpdateClient;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\CommandFactory;
use Tanaad\Shared\Ledger\EventEnvelope;
use Tanaad\Shared\Ledger\Rejection;

/** Offline envelopes → Orders commands. Validation lives in the handlers so HTTP and sync share one rule set. */
final class OrdersEnvelopeFactory implements CommandFactory
{
    public function fromEnvelope(EventEnvelope $envelope, CommandContext $context): Command
    {
        $p = $envelope->payload;

        return match ($envelope->type) {
            // One event type for both: a known client id is a change, a new one a registration.
            'orders.client_saved' => is_string($p['client_id'] ?? null) && DB::table('clients')->where('id', $p['client_id'])->exists()
                ? new UpdateClient($context, $p) : new RegisterClient($context, $p),
            'orders.created' => new CreateOrder($context, $p),
            'orders.cash_confirmed' => new ConfirmOrderCash($context, $p),
            'orders.approved' => new ApproveOrder($context, $p),
            'orders.held' => new HoldOrder($context, $p),
            'orders.invoiced' => new IssueInvoice($context, $p),
            'orders.payment_recorded' => new RecordPayment($context, $p),
            default => throw Rejection::unknownType($envelope->type),
        };
    }
}

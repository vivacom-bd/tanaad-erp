<?php

declare(strict_types=1);

namespace Tanaad\Cash\Factories;

use Tanaad\Cash\Commands\ReopenCashDay;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\CommandFactory;
use Tanaad\Shared\Ledger\EventEnvelope;

/**
 * Field validation lives in ReopenCashDayHandler so HTTP and sync share one rule set. The envelope reason fills a missing payload reason.
 * PIN approval is online only: approver fields are dropped from synced envelopes (a PIN must not sit in an
 * offline queue, and wrong PINs there would not count towards the lockout), so a synced reopen needs a manager actor.
 */
final class ReopenCashDayFactory implements CommandFactory
{
    public function fromEnvelope(EventEnvelope $envelope, CommandContext $context): Command
    {
        $payload = $envelope->payload;
        unset($payload['approver_user_id'], $payload['approver_pin']);
        if ($envelope->reason !== null && ! isset($payload['reason'])) {
            $payload['reason'] = $envelope->reason;
        }

        return new ReopenCashDay($context, $payload);
    }
}

<?php

declare(strict_types=1);

namespace Tanaad\Shared\Ledger;

/**
 * Builds a Command from a client event envelope (ADR-004). Validates the payload and
 * throws Rejection::invalidPayload() when it does not fit.
 */
interface CommandFactory
{
    public function fromEnvelope(EventEnvelope $envelope, CommandContext $context): Command;
}

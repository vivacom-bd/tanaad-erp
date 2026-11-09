<?php

declare(strict_types=1);

namespace Tanaad\Shared\Ledger;

/**
 * An intention to change the ledger. Commands are `final readonly` value objects built
 * either by an HTTP request or by the /events ingestion from a client envelope.
 */
interface Command
{
    public function context(): CommandContext;
}

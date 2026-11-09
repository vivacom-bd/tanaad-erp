<?php

declare(strict_types=1);

namespace Tanaad\Shared\Ledger;

/**
 * A side effect that runs after commit on the queue: messages, PDFs, exports. Must be
 * idempotent; the bus records each (reactor, event) run so a retry never repeats it.
 */
interface Reactor
{
    /** @return list<string> */
    public function handles(): array;

    public function react(StoredEvent $event): void;
}

<?php

declare(strict_types=1);

namespace Tanaad\Shared\Ledger;

/**
 * Keeps one or more read tables in step with the ledger. Applied synchronously in the
 * command transaction; must be deterministic and replayable from seq 0.
 */
interface Projector
{
    /** @return list<string> event types this projector reacts to */
    public function handles(): array;

    public function apply(StoredEvent $event): void;

    /** Truncate everything this projector owns, before a replay. */
    public function reset(): void;
}

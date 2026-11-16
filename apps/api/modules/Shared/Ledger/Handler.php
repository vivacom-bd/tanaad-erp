<?php

declare(strict_types=1);

namespace Tanaad\Shared\Ledger;

/**
 * Turns a command into facts. Checks invariants against projections and throws a
 * Rejection when the command cannot be accepted. Never writes projections itself.
 */
interface Handler
{
    /** @return list<Event> */
    public function handle(Command $command): array;
}

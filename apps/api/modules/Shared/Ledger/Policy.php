<?php

declare(strict_types=1);

namespace Tanaad\Shared\Ledger;

/**
 * A deterministic business rule that answers an event with follow-up commands in the
 * same transaction (an over-limit order raises an exception, a variance opens a case).
 */
interface Policy
{
    /** @return list<string> */
    public function handles(): array;

    /** @return list<Command> */
    public function react(StoredEvent $event): array;
}

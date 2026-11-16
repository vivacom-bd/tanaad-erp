<?php

declare(strict_types=1);

namespace Tanaad\Shared\Ledger;

/**
 * Role × site × separation-of-duties check for a command. Throws Rejection::forbidden().
 */
interface Authorizer
{
    public function authorise(Command $command): void;
}

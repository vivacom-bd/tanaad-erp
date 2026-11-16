<?php

declare(strict_types=1);

namespace Tanaad\Messaging\Support;

final class MessageStatus
{
    /** Final states: no further delivery attempt is made. */
    public const array SETTLED = ['logged', 'delivered', 'failed'];
}

<?php

declare(strict_types=1);

namespace Tanaad\Messaging\Support;

final readonly class DeliveryResult
{
    /** @param 'logged'|'delivered' $status */
    public function __construct(public string $status, public string $driver, public ?string $providerId = null) {}
}

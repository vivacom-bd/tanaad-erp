<?php

declare(strict_types=1);

namespace Tanaad\Messaging\Contracts;

use Tanaad\Messaging\Support\DeliveryResult;
use Tanaad\Messaging\Support\OutboundMessage;

interface Messenger
{
    /** @throws \Throwable when the message could not be handed over; the caller records the failure and retries */
    public function send(OutboundMessage $message): DeliveryResult;
}

<?php

declare(strict_types=1);

namespace Tanaad\Messaging\Drivers;

use Psr\Log\LoggerInterface;
use Tanaad\Messaging\Contracts\Messenger;
use Tanaad\Messaging\Support\DeliveryResult;
use Tanaad\Messaging\Support\OutboundMessage;

/** Writes the message to the application log. The outbound_messages row is the ledger-backed record. */
final class LogMessenger implements Messenger
{
    public function __construct(private readonly LoggerInterface $log) {}

    public function send(OutboundMessage $message): DeliveryResult
    {
        $this->log->info('Outbound message', ['id' => $message->id, 'to' => $message->to, 'body' => $message->body]);

        return new DeliveryResult('logged', 'log', $message->id);
    }
}

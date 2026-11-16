<?php

declare(strict_types=1);

namespace Tanaad\Messaging\Handlers;

use Illuminate\Database\ConnectionInterface;
use Tanaad\Messaging\Commands\QueueMessage;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;

/**
 * Server-only. Payload {message_id, user_id, to, source_event_id, source_type, template?, language?, params, body}.
 * The message id is derived from (source event, user), so a repeat of the same fact queues nothing.
 */
final class QueueMessageHandler implements Handler
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function handle(Command $command): array
    {
        if (! $command instanceof QueueMessage) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $p = $command->payload;
        if ($this->db->table('outbound_messages')->where('id', $p['message_id'] ?? '')->lockForUpdate()->exists()) {
            return [];
        }

        return [Event::from($command->context(), 'messaging.queued', $p)];
    }
}

<?php

declare(strict_types=1);

namespace Tanaad\Messaging\Handlers;

use Illuminate\Database\ConnectionInterface;
use Tanaad\Messaging\Commands\RecordDelivery;
use Tanaad\Messaging\Support\MessageStatus;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;

/** Server-only. Payload {message_id, attempt, status (logged|delivered), driver, provider_id?}. A message settles once. */
final class RecordDeliveryHandler implements Handler
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function handle(Command $command): array
    {
        if (! $command instanceof RecordDelivery) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $p = $command->payload;
        $id = (string) ($p['message_id'] ?? '');
        $row = $this->db->table('outbound_messages')->where('id', $id)->lockForUpdate()->first(['status']);
        if ($row === null) {
            throw Rejection::notFound('Message', $id);
        }
        if (in_array($row->status, MessageStatus::SETTLED, true)) {
            return [];
        }

        return [Event::from($command->context(), 'messaging.delivered', $p)];
    }
}

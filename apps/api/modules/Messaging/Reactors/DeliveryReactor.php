<?php

declare(strict_types=1);

namespace Tanaad\Messaging\Reactors;

use Tanaad\Messaging\Jobs\DeliverMessage;
use Tanaad\Shared\Ledger\Reactor;
use Tanaad\Shared\Ledger\StoredEvent;

/** After commit, hand each queued message to the delivery job. */
final class DeliveryReactor implements Reactor
{
    public function handles(): array
    {
        return ['messaging.queued'];
    }

    public function react(StoredEvent $event): void
    {
        DeliverMessage::dispatch((string) $event->payload()['message_id']);
    }
}

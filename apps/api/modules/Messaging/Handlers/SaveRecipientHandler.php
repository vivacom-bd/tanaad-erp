<?php

declare(strict_types=1);

namespace Tanaad\Messaging\Handlers;

use Illuminate\Database\ConnectionInterface;
use Tanaad\Config\Support\Payload;
use Tanaad\Messaging\Commands\SaveRecipient;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;

/** Payload {user_id, phone, opted_out?}. Event messaging.recipient_saved: {user_id, phone, opted_out}. */
final class SaveRecipientHandler implements Handler
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function handle(Command $command): array
    {
        if (! $command instanceof SaveRecipient) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $p = new Payload($command->payload);
        $userId = $p->uuid('user_id');
        $raw = $p->str('phone', 32);
        $optedOut = $p->bool('opted_out', false);
        $phone = '+'.preg_replace('/\D/', '', $raw);
        if (preg_match('/^\+[1-9]\d{7,14}$/', $phone) !== 1) {
            $p->error('phone', 'must be an international number, e.g. +256700000000');
        }
        $p->check();

        $active = $this->db->table('users')->where('id', $userId)->value('active');
        if ($active === null || ! $active) {
            throw Rejection::invalidPayload(['user_id' => 'must be an active user']);
        }

        return [Event::from($command->context(), 'messaging.recipient_saved', ['user_id' => $userId, 'phone' => $phone, 'opted_out' => $optedOut])];
    }
}

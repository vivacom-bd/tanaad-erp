<?php

declare(strict_types=1);

namespace Tanaad\Messaging\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Tanaad\Messaging\Commands\RecordDelivery;
use Tanaad\Messaging\Commands\RecordFailure;
use Tanaad\Messaging\Contracts\Messenger;
use Tanaad\Messaging\Support\MessageStatus;
use Tanaad\Messaging\Support\OutboundMessage;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\CommandContext;
use Throwable;

/**
 * One delivery attempt for one message. Idempotent: a settled message is skipped, and the attempt number
 * comes from the ledger-backed row, so a re-run after a crash neither double-sends a settled message nor
 * loses count. A failed attempt is recorded and re-queued with backoff until tanaad.messaging.max_attempts.
 * A provider-accepted send is written to message_send_receipts before RecordDelivery is dispatched; if recording
 * then fails, the retry finds the receipt and only records the delivery, never sending the message twice.
 */
final class DeliverMessage implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Queue tries follow the configured delivery attempts (each failed attempt releases the job once). */
    public function tries(): int
    {
        return max(1, (int) config('tanaad.messaging.max_attempts', 5));
    }

    public function __construct(public readonly string $messageId) {}

    public function handle(CommandBus $bus, Messenger $messenger, ConnectionInterface $db, Config $config): void
    {
        $row = $db->table('outbound_messages')->where('id', $this->messageId)->first();
        if ($row === null || in_array($row->status, MessageStatus::SETTLED, true)) {
            return;
        }
        $attempt = (int) $row->attempts + 1;
        $max = max(1, (int) $config->get('tanaad.messaging.max_attempts', 5));
        /** @var list<string> $params */
        $params = json_decode((string) $row->params, true, 512, JSON_THROW_ON_ERROR);
        $context = CommandContext::system($row->site_id);

        $sent = $db->table('message_send_receipts')->where('message_id', $this->messageId)->first();
        if ($sent !== null) {
            $bus->dispatch(new RecordDelivery($context, [
                'message_id' => $this->messageId, 'attempt' => (int) $sent->attempt, 'status' => $sent->status, 'driver' => $sent->driver, 'provider_id' => $sent->provider_id,
            ]));

            return;
        }

        try {
            $result = $messenger->send(new OutboundMessage($row->id, $row->to_phone, $row->body, $row->template, $row->language, $params));
        } catch (Throwable $e) {
            $final = $attempt >= $max;
            $bus->dispatch(new RecordFailure($context, [
                'message_id' => $this->messageId, 'attempt' => $attempt, 'final' => $final, 'error' => mb_substr($e->getMessage(), 0, 500),
            ]));
            if (! $final) {
                /** @var list<int> $backoff */
                $backoff = (array) $config->get('tanaad.messaging.backoff', [30, 120, 600, 1800]);
                $this->release($backoff[min($attempt, count($backoff)) - 1] ?? 60);
            }

            return;
        }

        $db->table('message_send_receipts')->insertOrIgnore([
            'message_id' => $this->messageId, 'attempt' => $attempt, 'status' => $result->status, 'driver' => $result->driver,
            'provider_id' => $result->providerId, 'created_at' => \Carbon\CarbonImmutable::now(),
        ]);
        $bus->dispatch(new RecordDelivery($context, [
            'message_id' => $this->messageId, 'attempt' => $attempt, 'status' => $result->status, 'driver' => $result->driver, 'provider_id' => $result->providerId,
        ]));
    }
}

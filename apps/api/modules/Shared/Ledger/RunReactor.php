<?php

declare(strict_types=1);

namespace Tanaad\Shared\Ledger;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Queued job that runs one reactor for one event, exactly once.
 */
final class RunReactor implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    /** @param class-string<Reactor> $reactor */
    public function __construct(
        public readonly string $reactor,
        public readonly string $eventId,
    ) {}

    public function handle(ConnectionInterface $db, EventStore $store): void
    {
        $already = $db->table('reactor_runs')
            ->where(['reactor' => $this->reactor, 'event_id' => $this->eventId])
            ->whereNull('error')
            ->exists();
        if ($already) {
            return;
        }

        $event = $store->find($this->eventId);
        if ($event === null) {
            return;
        }

        /** @var Reactor $reactor */
        $reactor = app($this->reactor);
        try {
            $reactor->react($event);
            $this->record($db, null);
        } catch (Throwable $e) {
            $this->record($db, $e->getMessage());
            throw $e;
        }
    }

    private function record(ConnectionInterface $db, ?string $error): void
    {
        $db->table('reactor_runs')->upsert(
            [['reactor' => $this->reactor, 'event_id' => $this->eventId, 'ran_at' => CarbonImmutable::now(), 'error' => $error]],
            ['reactor', 'event_id'],
            ['ran_at', 'error'],
        );
    }
}

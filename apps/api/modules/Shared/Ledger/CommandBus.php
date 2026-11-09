<?php

declare(strict_types=1);

namespace Tanaad\Shared\Ledger;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\ConnectionInterface;

/**
 * The one write path. authorise → handle → append → project → policies → commit → reactors.
 */
final class CommandBus
{
    private bool $rulesEnabled = true;

    /** @var list<array{class-string<Reactor>, string}> reactors to queue after commit */
    private array $pendingReactors = [];

    public function __construct(
        private readonly Registry $registry,
        private readonly EventStore $store,
        private readonly ConnectionInterface $db,
        private readonly Dispatcher $queue,
        private readonly Config $config,
    ) {}

    /**
     * @throws Rejection
     */
    public function dispatch(Command $command, ?string $batchId = null): CommandResult
    {
        $eventId = $command->context()->eventId;
        if ($eventId !== null) {
            $existing = $this->store->find($eventId);
            if ($existing !== null) {
                return CommandResult::duplicate($existing);
            }
        }

        $result = $this->db->transaction(fn (): CommandResult => $this->execute($command, $batchId, 0));

        $this->flushReactors();

        return $result;
    }

    /**
     * Apply stored events to projectors only, with no handlers, policies or reactors.
     * Used by Replay.
     */
    public function project(StoredEvent $event): void
    {
        foreach ($this->registry->projectorsFor($event->type()) as $projector) {
            $projector->apply($event);
        }
        $this->advanceCursors($event);
    }

    private function execute(Command $command, ?string $batchId, int $depth): CommandResult
    {
        $maxDepth = (int) $this->config->get('tanaad.policy_depth_max', 5);
        if ($depth > $maxDepth) {
            throw new \LogicException("Policy chain deeper than {$maxDepth}; a policy is probably reacting to its own event.");
        }

        $this->registry->authorizerFor($command)?->authorise($command);

        $events = $this->registry->handlerFor($command)->handle($command);
        if ($events === []) {
            return new CommandResult('accepted', []);
        }

        $stored = $this->store->append($events, $batchId);

        foreach ($stored as $event) {
            $this->project($event);
        }

        if ($this->rulesEnabled) {
            foreach ($stored as $event) {
                foreach ($this->registry->policiesFor($event->type()) as $policy) {
                    foreach ($policy->react($event) as $followUp) {
                        $this->execute($followUp, $batchId, $depth + 1);
                    }
                }
                foreach ($this->registry->reactorsFor($event->type()) as $reactor) {
                    $this->pendingReactors[] = [$reactor::class, $event->id()];
                }
            }
        }

        return new CommandResult('accepted', $stored, $this->assigned($command, $stored));
    }

    /**
     * @param  list<StoredEvent>  $stored
     * @return array<string, string>
     */
    private function assigned(Command $command, array $stored): array
    {
        $ref = $command->context()->provisionalRef;
        if ($ref === null) {
            return [];
        }
        foreach ($stored as $event) {
            $number = $event->payload()['number'] ?? null;
            if (is_string($number)) {
                return [$ref => $number];
            }
        }

        return [];
    }

    private function advanceCursors(StoredEvent $event): void
    {
        foreach ($this->registry->projectorsFor($event->type()) as $projector) {
            $this->db->table('projection_cursors')->upsert(
                [['projector' => $projector::class, 'last_seq' => $event->seq, 'updated_at' => CarbonImmutable::now()]],
                ['projector'],
                ['last_seq', 'updated_at'],
            );
        }
    }

    private function flushReactors(): void
    {
        $pending = $this->pendingReactors;
        $this->pendingReactors = [];
        foreach ($pending as [$reactor, $eventId]) {
            $this->queue->dispatch(new RunReactor($reactor, $eventId));
        }
    }
}

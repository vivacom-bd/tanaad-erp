<?php

declare(strict_types=1);

namespace Tanaad\Shared\Ledger;

use Illuminate\Database\ConnectionInterface;

/**
 * Rebuilds projections from the ledger. Policies and reactors stay off: the facts they
 * would have produced are already in the ledger.
 */
final class Replay
{
    public function __construct(
        private readonly Registry $registry,
        private readonly EventStore $store,
        private readonly ConnectionInterface $db,
    ) {}

    /**
     * @param  list<class-string<Projector>>|null  $only  null = every projector
     * @return int events replayed
     */
    public function rebuild(?array $only = null, int $fromSeq = 0, ?callable $progress = null): int
    {
        $projectors = $this->registry->allProjectors();
        if ($only !== null) {
            $projectors = array_values(array_filter($projectors, fn (Projector $p): bool => in_array($p::class, $only, true)));
        }
        $types = [];
        foreach ($projectors as $projector) {
            foreach ($projector->handles() as $type) {
                $types[$type][] = $projector;
            }
        }

        $count = 0;
        $this->db->transaction(function () use ($projectors, $types, $fromSeq, $progress, &$count): void {
            if ($fromSeq === 0) {
                foreach ($projectors as $projector) {
                    $projector->reset();
                    $this->db->table('projection_cursors')->where('projector', $projector::class)->delete();
                }
            }
            foreach ($this->store->stream($fromSeq) as $event) {
                foreach ($types[$event->type()] ?? [] as $projector) {
                    $projector->apply($event);
                    $this->db->table('projection_cursors')->upsert(
                        [['projector' => $projector::class, 'last_seq' => $event->seq, 'updated_at' => now()]],
                        ['projector'],
                        ['last_seq', 'updated_at'],
                    );
                }
                $count++;
                if ($progress !== null) {
                    $progress($event);
                }
            }
        });

        return $count;
    }
}

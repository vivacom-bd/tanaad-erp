<?php

declare(strict_types=1);

namespace Tests\Fixtures\Notes;

use Illuminate\Database\ConnectionInterface;
use Tanaad\Shared\Ledger\Projector;
use Tanaad\Shared\Ledger\StoredEvent;

final class NoteProjector implements Projector
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function handles(): array
    {
        return ['notes.note_added', 'notes.note_flagged'];
    }

    public function apply(StoredEvent $event): void
    {
        $p = $event->payload();
        match ($event->type()) {
            'notes.note_added' => $this->db->table('test_notes')->insert([
                'id' => $event->id(),
                'site_id' => $event->siteId(),
                'number' => $p['number'],
                'day' => $p['day'],
                'text' => $p['text'],
                'flagged' => false,
                'seq' => $event->seq,
            ]),
            'notes.note_flagged' => $this->db->table('test_notes')->where('id', $p['note_id'])->update(['flagged' => true, 'seq' => $event->seq]),
            default => null,
        };
    }

    public function reset(): void
    {
        $this->db->table('test_notes')->delete();
    }
}

<?php

declare(strict_types=1);

namespace Tests\Fixtures\Notes;

use Tanaad\Shared\Ledger\Reactor;
use Tanaad\Shared\Ledger\StoredEvent;

final class NoteReactor implements Reactor
{
    /** @var list<string> */
    public static array $seen = [];

    public function handles(): array
    {
        return ['notes.note_added'];
    }

    public function react(StoredEvent $event): void
    {
        self::$seen[] = $event->id();
    }
}

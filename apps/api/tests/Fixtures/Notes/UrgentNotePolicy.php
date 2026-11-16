<?php

declare(strict_types=1);

namespace Tests\Fixtures\Notes;

use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\Policy;
use Tanaad\Shared\Ledger\StoredEvent;

final class UrgentNotePolicy implements Policy
{
    public function handles(): array
    {
        return ['notes.note_added'];
    }

    public function react(StoredEvent $event): array
    {
        if (($event->payload()['urgent'] ?? false) !== true) {
            return [];
        }

        return [new FlagNote(CommandContext::system($event->siteId()), $event->id())];
    }
}

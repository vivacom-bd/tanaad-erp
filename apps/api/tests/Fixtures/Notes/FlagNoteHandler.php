<?php

declare(strict_types=1);

namespace Tests\Fixtures\Notes;

use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;

final class FlagNoteHandler implements Handler
{
    public function handle(Command $command): array
    {
        assert($command instanceof FlagNote);

        return [Event::from($command->context(), 'notes.note_flagged', ['note_id' => $command->noteId], primary: false)];
    }
}

<?php

declare(strict_types=1);

namespace Tests\Fixtures\Notes;

use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\DayLocks;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\NumberSeries;
use Tanaad\Shared\Ledger\Rejection;

final class AddNoteHandler implements Handler
{
    public function __construct(private readonly DayLocks $locks, private readonly NumberSeries $numbers) {}

    public function handle(Command $command): array
    {
        assert($command instanceof AddNote);
        $ctx = $command->context();
        if ($ctx->siteId === null) {
            throw Rejection::invalidPayload(['site' => 'required']);
        }
        if (trim($command->text) === '') {
            throw Rejection::invalidPayload(['text' => 'must not be empty']);
        }
        $this->locks->assertOpen($ctx->siteId, 'notes', $command->day);

        return [Event::from($ctx, 'notes.note_added', [
            'number' => $this->numbers->allocate($ctx->siteId, 'note'),
            'day' => $command->day,
            'text' => $command->text,
            'urgent' => $command->urgent,
        ])];
    }
}

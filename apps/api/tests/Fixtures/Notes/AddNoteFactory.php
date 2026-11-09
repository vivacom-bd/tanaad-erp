<?php

declare(strict_types=1);

namespace Tests\Fixtures\Notes;

use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\CommandFactory;
use Tanaad\Shared\Ledger\EventEnvelope;
use Tanaad\Shared\Ledger\Rejection;

final class AddNoteFactory implements CommandFactory
{
    public function fromEnvelope(EventEnvelope $envelope, CommandContext $context): Command
    {
        $p = $envelope->payload;
        $errors = [];
        if (! is_string($p['day'] ?? null)) {
            $errors['day'] = 'required';
        }
        if (! is_string($p['text'] ?? null)) {
            $errors['text'] = 'required';
        }
        if ($errors !== []) {
            throw Rejection::invalidPayload($errors);
        }

        return new AddNote($context, $p['day'], $p['text'], (bool) ($p['urgent'] ?? false));
    }
}

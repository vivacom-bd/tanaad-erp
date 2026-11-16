<?php

declare(strict_types=1);

namespace Tanaad\People\Handlers;

use Tanaad\Config\Support\Payload;
use Tanaad\People\Commands\ArchivePerson;
use Tanaad\People\Support\Sites;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;

/** Acts at the person's home site only; wage and attendance history is kept. */
final class ArchivePersonHandler implements Handler
{
    public function handle(Command $command): array
    {
        if (! $command instanceof ArchivePerson) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $site = Sites::of($command);
        $p = new Payload($command->payload);
        $payload = ['person_id' => $p->uuid('person_id'), 'reason' => $p->optStr('reason'), 'day' => Sites::today($site, $command)];
        $p->check();

        $person = Sites::person($payload['person_id']);
        if ($person->site_id !== $site->id) {
            throw Rejection::notFound('Person', $payload['person_id']);
        }
        if ($person->status !== 'active') {
            throw Rejection::stateConflict("{$person->full_name} is already archived.");
        }

        return [Event::from($command->context(), 'people.person_archived', $payload)];
    }
}

<?php

declare(strict_types=1);

namespace Tanaad\People\Handlers;

use Tanaad\Config\Support\Payload;
use Tanaad\People\Commands\StartEngagement;
use Tanaad\People\Models\Engagement;
use Tanaad\People\Support\EngagementTerms;
use Tanaad\People\Support\Sites;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;

/** A new engagement of an existing, active person at the command's site. */
final class StartEngagementHandler implements Handler
{
    public function handle(Command $command): array
    {
        if (! $command instanceof StartEngagement) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $site = Sites::of($command);
        $p = new Payload($command->payload);
        $personId = $p->uuid('person_id');
        $terms = EngagementTerms::read($p, Sites::today($site, $command));
        $p->check();

        $person = Sites::person($personId);
        if ($person->status !== 'active') {
            throw Rejection::stateConflict("{$person->full_name} is archived; reactivate them first.");
        }
        if (Engagement::query()->where(['person_id' => $personId, 'site_id' => $site->id, 'status' => 'active'])->exists()) {
            throw Rejection::stateConflict("{$person->full_name} already has an active engagement at this site; renew or end it.");
        }

        return [Event::from($command->context(), 'people.engagement_started', ['person_id' => $personId, ...$terms])];
    }
}

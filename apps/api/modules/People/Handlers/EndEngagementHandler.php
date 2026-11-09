<?php

declare(strict_types=1);

namespace Tanaad\People\Handlers;

use Tanaad\Config\Support\Payload;
use Tanaad\People\Commands\EndEngagement;
use Tanaad\People\Models\Engagement;
use Tanaad\People\Support\Sites;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;

final class EndEngagementHandler implements Handler
{
    public function handle(Command $command): array
    {
        if (! $command instanceof EndEngagement) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $site = Sites::of($command);
        $p = new Payload($command->payload);
        $id = $p->uuid('engagement_id');
        $end = $p->optDate('end_date') ?? Sites::today($site, $command);
        $reason = $p->optStr('reason');
        $p->check();

        $e = Engagement::query()->where(['id' => $id, 'site_id' => $site->id])->first() ?? throw Rejection::notFound('Engagement', $id);
        if ($e->status !== 'active') {
            throw Rejection::stateConflict("Engagement {$id} is already {$e->status}.");
        }
        if ($end < $e->start_date) {
            throw Rejection::invalidPayload(['end_date' => 'must not be before the engagement start']);
        }

        return [Event::from($command->context(), 'people.engagement_ended', [
            'engagement_id' => $id, 'person_id' => $e->person_id, 'end_date' => $end, 'reason' => $reason,
        ])];
    }
}

<?php

declare(strict_types=1);

namespace Tanaad\People\Handlers;

use Illuminate\Support\Str;
use Tanaad\Config\Support\Payload;
use Tanaad\People\Commands\RenewEngagement;
use Tanaad\People\Models\Engagement;
use Tanaad\People\Support\EngagementTerms;
use Tanaad\People\Support\Sites;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;

/**
 * Prototype renewContract: the old engagement becomes "renewed" and a fresh one with the
 * same terms starts (today by default) and runs for term_days.
 */
final class RenewEngagementHandler implements Handler
{
    public function handle(Command $command): array
    {
        if (! $command instanceof RenewEngagement) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $site = Sites::of($command);
        $p = new Payload($command->payload);
        $oldId = $p->uuid('engagement_id');
        $termDays = $p->int('term_days', 1);
        $start = $p->optDate('start_date') ?? Sites::today($site, $command);
        $newId = $p->optUuid('new_engagement_id') ?? (string) Str::uuid7();
        $p->check();

        $old = Engagement::query()->where(['id' => $oldId, 'site_id' => $site->id])->first() ?? throw Rejection::notFound('Engagement', $oldId);
        if ($old->status !== 'active') {
            throw Rejection::stateConflict("Engagement {$oldId} is {$old->status}; only an active engagement can be renewed.");
        }

        return [Event::from($command->context(), 'people.engagement_renewed', [
            'engagement_id' => $newId,
            'renews_engagement_id' => $oldId,
            'person_id' => $old->person_id,
            'type' => $old->type,
            'start_date' => $start,
            'end_date' => EngagementTerms::addDays($start, $termDays),
            'term_days' => $termDays,
            'rate_basis' => $old->rate_basis,
            'rate' => $old->rate,
            'currency' => $old->currency,
            'settlement_cycle' => $old->settlement_cycle,
        ])];
    }
}

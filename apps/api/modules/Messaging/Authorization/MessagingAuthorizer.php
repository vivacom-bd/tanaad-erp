<?php

declare(strict_types=1);

namespace Tanaad\Messaging\Authorization;

use Tanaad\Identity\Access;
use Tanaad\Identity\Models\User;
use Tanaad\Identity\Role;
use Tanaad\Messaging\Commands\SaveRecipient;
use Tanaad\Shared\Ledger\Authorizer;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Rejection;

/**
 * ADR-006 for messaging. Recipients are kept by the group admin. Queueing, delivery and failure
 * are server facts: only the system (no actor) may issue them, never a signed-in user.
 */
final class MessagingAuthorizer implements Authorizer
{
    public function __construct(private readonly Access $access) {}

    public function authorise(Command $command): void
    {
        $actorId = $command->context()->actorId;
        if (! $command instanceof SaveRecipient) {
            if ($actorId !== null) {
                throw Rejection::forbidden('Messages are queued and delivered by the system only.');
            }

            return;
        }
        $actor = $actorId === null ? null : User::query()->with('siteRoles')->find($actorId);
        if ($actor === null || ! $this->access->holdsAny($actor, null, [Role::GroupAdmin])) {
            throw Rejection::forbidden('Only the group admin may manage message recipients.');
        }
    }

    /**
     * The message log: group roles see all (null); a site manager sees the messages of their own sites.
     *
     * @return list<string>|null
     */
    public function readableSites(User $actor): ?array
    {
        if ($this->access->hasGroupRole($actor)) {
            return null;
        }
        $sites = array_values($actor->siteRoles->where('role', Role::SiteManager->value)->pluck('site_id')->filter()->map(fn ($id): string => (string) $id)->unique()->all());
        if ($sites === []) {
            throw Rejection::forbidden('Only the CEO, the group admin or a site manager reads the message log.');
        }

        return $sites;
    }
}

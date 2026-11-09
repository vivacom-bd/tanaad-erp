<?php

declare(strict_types=1);

namespace Tanaad\People\Authorization;

use Tanaad\Identity\Access;
use Tanaad\Identity\Models\User;
use Tanaad\Identity\Role;
use Tanaad\People\Commands\ApproveSettlement;
use Tanaad\People\Commands\ArchivePerson;
use Tanaad\People\Commands\CancelSettlementRun;
use Tanaad\People\Commands\GiveAdvance;
use Tanaad\People\Commands\MarkAttendance;
use Tanaad\People\Commands\ReactivatePerson;
use Tanaad\People\Commands\RecordPayout;
use Tanaad\People\Commands\RegisterPerson;
use Tanaad\Shared\Ledger\Authorizer;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Rejection;

/**
 * ADR-006 for People, always at the command's own site:
 * - Supervisor: mark attendance, register casuals.
 * - Site Manager: everything at their site. Group Admin: everything everywhere.
 * - CEO: archive, reactivate, approve and cancel settlement runs.
 * - Cashier: record payouts and give advances.
 * - Approving a run: never its creator (checked in ApproveSettlementHandler).
 * - The server itself (no actor) may archive; that is the people:archive-absent job.
 */
final class PeopleAuthorizer implements Authorizer
{
    public function __construct(private readonly Access $access) {}

    public function authorise(Command $command): void
    {
        $context = $command->context();
        if ($context->actorId === null && $command instanceof ArchivePerson) {
            return;
        }
        $actor = $context->actorId === null ? null : User::query()->with('siteRoles')->find($context->actorId);
        if ($actor === null) {
            throw Rejection::forbidden('Sign in first.');
        }
        $siteId = $context->siteId ?? throw Rejection::forbidden('People commands act at a site.');

        $roles = [Role::GroupAdmin, Role::SiteManager];
        if ($command instanceof ArchivePerson || $command instanceof ReactivatePerson || $command instanceof ApproveSettlement || $command instanceof CancelSettlementRun) {
            $roles[] = Role::Ceo;
        }
        if ($command instanceof RecordPayout || $command instanceof GiveAdvance) {
            $roles[] = Role::Cashier;
        }
        if ($command instanceof MarkAttendance || ($command instanceof RegisterPerson && $this->registersCasual($command))) {
            $roles[] = Role::Supervisor;
        }
        if (! $this->access->holdsAny($actor, $siteId, $roles)) {
            throw Rejection::forbidden('This role may not do this at this site.');
        }
    }

    private function registersCasual(RegisterPerson $command): bool
    {
        $engagement = $command->payload['engagement'] ?? [];

        return ! is_array($engagement) || ($engagement['type'] ?? 'casual') === 'casual';
    }
}

<?php

declare(strict_types=1);

namespace Tanaad\Identity\Handlers;

use Tanaad\Config\Models\Site;
use Tanaad\Identity\Commands\AssignRole;
use Tanaad\Identity\Models\User;
use Tanaad\Identity\Models\UserSiteRole;
use Tanaad\Identity\Role;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\Rejection;

final class AssignRoleHandler implements Handler
{
    public function handle(Command $command): array
    {
        if (! $command instanceof AssignRole) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $role = Role::tryFrom($command->role) ?? throw Rejection::invalidPayload(['role' => 'unknown role']);
        if ($role->isGroup() !== ($command->roleSiteId === null)) {
            throw Rejection::invalidPayload(['site_id' => $role->isGroup() ? 'a group role has no site' : 'a site role needs a site']);
        }
        if (! User::query()->whereKey($command->userId)->exists()) {
            throw Rejection::notFound('User', $command->userId);
        }
        if ($command->roleSiteId !== null && ! Site::query()->whereKey($command->roleSiteId)->exists()) {
            throw Rejection::notFound('Site', $command->roleSiteId);
        }
        $exists = UserSiteRole::query()->where(['user_id' => $command->userId, 'role' => $role->value])
            ->where('site_id', $command->roleSiteId)->whereNull('deleted_at')->exists();
        if ($exists) {
            throw Rejection::duplicateFact('The user already holds this role here.');
        }

        return [Event::from($command->context(), 'identity.role_assigned', [
            'user_id' => $command->userId,
            'role' => $role->value,
            'role_site_id' => $command->roleSiteId,
        ])];
    }
}

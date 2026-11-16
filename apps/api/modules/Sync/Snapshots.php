<?php

declare(strict_types=1);

namespace Tanaad\Sync;

/**
 * Projections a client may pull as snapshot deltas (§8.2). Modules register
 * name → table; group-shared tables also return rows with a null site_id.
 */
final class Snapshots
{
    /** @var array<string, array{table: string, siteScoped: bool, roles: list<string>|null}> */
    private array $projections = [];

    /** @param list<string>|null $roles site roles that may pull it (group roles per Access::canAct); null = any site member */
    public function register(string $name, string $table, bool $siteScoped = true, ?array $roles = null): self
    {
        $this->projections[$name] = ['table' => $table, 'siteScoped' => $siteScoped, 'roles' => $roles];

        return $this;
    }

    /** @return array{table: string, siteScoped: bool, roles: list<string>|null}|null */
    public function find(string $name): ?array
    {
        return $this->projections[$name] ?? null;
    }
}

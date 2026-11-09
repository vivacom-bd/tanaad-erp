<?php

declare(strict_types=1);

namespace Tanaad\People\Support;

use Tanaad\Config\Models\Site;
use Tanaad\People\Models\Engagement;
use Tanaad\People\Models\Person;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Rejection;

/** Lookups every People handler needs. */
final class Sites
{
    public static function of(Command $command): Site
    {
        $siteId = $command->context()->siteId ?? throw Rejection::invalidPayload(['site_id' => 'required']);

        return Site::query()->find($siteId) ?? throw Rejection::notFound('Site', $siteId);
    }

    /** Business day of the command in the site's calendar (ADR-008). */
    public static function today(Site $site, Command $command): string
    {
        return $site->calendar()->businessDay($command->context()->occurredAt);
    }

    /** Home site, listed in shared_sites, or an active engagement there. */
    public static function engagedAt(Person $person, Site $site): bool
    {
        return $person->site_id === $site->id
            || in_array($site->id, $person->shared_sites ?? [], true)
            || Engagement::query()->where(['person_id' => $person->id, 'site_id' => $site->id, 'status' => 'active'])->exists();
    }

    public static function person(string $id): Person
    {
        return Person::query()->find($id) ?? throw Rejection::notFound('Person', $id);
    }
}

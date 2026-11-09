<?php

declare(strict_types=1);

namespace Tanaad\People\Handlers;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Str;
use Tanaad\Config\Models\Site;
use Tanaad\Config\Support\Payload;
use Tanaad\People\Commands\RegisterPerson;
use Tanaad\People\Models\Person;
use Tanaad\People\Support\EngagementTerms;
use Tanaad\People\Support\Sites;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\Event;
use Tanaad\Shared\Ledger\Handler;
use Tanaad\Shared\Ledger\NumberSeries;
use Tanaad\Shared\Ledger\Rejection;

/**
 * Registers a person at the command's site with their first engagement (prototype
 * registerCasual). The person number comes from the site's "person" number series.
 * Engagement terms default to a weekly-settled casual day rate, as the prototype does.
 */
final class RegisterPersonHandler implements Handler
{
    public const string SERIES = 'person';

    public function __construct(private readonly NumberSeries $series, private readonly Config $config) {}

    public function handle(Command $command): array
    {
        if (! $command instanceof RegisterPerson) {
            throw new \LogicException('Wrong command for '.self::class);
        }
        $site = Sites::of($command);
        $today = Sites::today($site, $command);
        $p = new Payload($command->payload);
        $fullName = $p->str('full_name');
        $person = [
            'person_id' => $p->optUuid('person_id') ?? (string) Str::uuid7(),
            'full_name' => $fullName,
            'id_number' => $p->optStr('id_number', 64),
            'phone_momo' => $p->optStr('phone_momo', 32),
            'sector' => $p->optStr('sector', 120),
            'shared' => $p->bool('shared', false),
            'shared_sites' => $this->sharedSites($p, $command->payload['shared_sites'] ?? null),
            'avatar_seed' => self::avatarSeed($fullName),
            'joined_on' => $p->optDate('joined_on') ?? $today,
        ];
        $raw = $command->payload['engagement'] ?? [];
        if (! is_array($raw)) {
            $p->error('engagement', 'must be an object');
            $raw = [];
        }
        /** @var array<string, mixed> $raw */
        $defaults = ['type' => 'casual', 'rate_basis' => 'day', 'settlement_cycle' => 'weekly', 'currency' => (string) $this->config->get('tanaad.currency')];
        $engagement = EngagementTerms::read(new Payload([...$defaults, ...$raw], 'engagement.', $p), $person['joined_on']);
        $p->check();

        if (Person::query()->whereKey($person['person_id'])->exists()) {
            throw Rejection::stateConflict("Person {$person['person_id']} is already registered.");
        }
        $person['number'] = $this->series->allocate($site->id, self::SERIES);

        return [
            Event::from($command->context(), 'people.person_registered', $person),
            Event::from($command->context(), 'people.engagement_started', ['person_id' => $person['person_id'], ...$engagement], primary: false),
        ];
    }

    /** @return list<string> site ids the person may also be marked at */
    private function sharedSites(Payload $p, mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        if (! is_array($raw) || ! array_is_list($raw)) {
            $p->error('shared_sites', 'must be a list of site ids');

            return [];
        }
        $out = [];
        foreach ($raw as $i => $id) {
            if (! is_string($id) || ! Str::isUuid($id) || ! Site::query()->whereKey($id)->exists()) {
                $p->error("shared_sites.{$i}", 'must be a site id');

                continue;
            }
            $out[] = strtolower($id);
        }

        return array_values(array_unique($out));
    }

    public static function avatarSeed(string $fullName): string
    {
        $initials = array_map(fn (string $w): string => mb_substr($w, 0, 1), preg_split('/\s+/', trim($fullName)) ?: []);

        return mb_strtoupper(mb_substr(implode('', $initials), 0, 2));
    }
}

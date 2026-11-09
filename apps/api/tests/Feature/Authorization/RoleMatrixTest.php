<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Tanaad\Config\Commands\ChangeRefList;
use Tanaad\Config\Commands\DefineNumberSeries;
use Tanaad\Config\Commands\SaveMachine;
use Tanaad\Config\Commands\SaveProduct;
use Tanaad\Config\Commands\SaveStage;
use Tanaad\Config\Commands\SaveZone;
use Tanaad\Config\Commands\SetBenchmark;
use Tanaad\Config\Commands\SetFxRate;
use Tanaad\Config\Commands\SetPrice;
use Tanaad\Config\Commands\UpdateCompanyProfile;
use Tanaad\Config\Commands\UpdateSiteSettings;
use Tanaad\Config\Database\Seeders\SitesSeeder;
use Tanaad\Config\Models\Site;
use Tanaad\Identity\Commands\AssignRole;
use Tanaad\Identity\Commands\OpenDeviceSession;
use Tanaad\Identity\Commands\RegisterDevice;
use Tanaad\Identity\Database\Seeders\DemoUsersSeeder;
use Tanaad\Identity\Models\User;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\Registry;
use Tanaad\Shared\Ledger\Rejection;
use Tanaad\Shared\Ledger\RejectionCode;

/**
 * ADR-006: the role x command matrix. Adding a command without a policy row fails the build.
 *
 * @return list<array{class-string<Command>, string, ?string, string}>
 */
function roleMatrix(): array
{
    $rows = [];
    $add = function (string $command, array $outcomes, ?string $site = 'mats') use (&$rows): void {
        foreach ($outcomes as $actor => $expected) {
            $rows[] = [$command, $actor, $site, $expected];
        }
    };

    $adminOnly = ['u_admin' => 'allowed', 'u_sm_mats' => 'forbidden', 'u_sm_bags' => 'forbidden', 'u_cash_mats' => 'forbidden', 'u_ceo1' => 'forbidden'];
    $add(RegisterDevice::class, $adminOnly);
    $add(AssignRole::class, $adminOnly);
    $add(OpenDeviceSession::class, array_fill_keys(array_keys($adminOnly), 'allowed'));

    // Site-scoped configuration: group admin, or the site manager of the command's own site.
    $siteScoped = ['u_admin' => 'allowed', 'u_sm_mats' => 'allowed', 'u_sm_bags' => 'forbidden', 'u_cash_mats' => 'forbidden', 'u_ceo1' => 'forbidden'];
    foreach ([DefineNumberSeries::class, SaveMachine::class, SaveProduct::class, SaveStage::class, SaveZone::class, SetBenchmark::class, SetPrice::class, UpdateSiteSettings::class, ChangeRefList::class] as $command) {
        $add($command, $siteScoped);
    }

    // Group-wide only.
    $add(SetFxRate::class, $adminOnly);
    $add(UpdateCompanyProfile::class, $adminOnly);

    // No site on the command means a group-wide change, which a site manager may not make.
    $add(SaveProduct::class, ['u_admin' => 'allowed', 'u_sm_mats' => 'forbidden'], null);

    return $rows;
}

function matrixCommand(string $class, CommandContext $context): Command
{
    return match ($class) {
        RegisterDevice::class => new RegisterDevice($context, 'dev_x', 'Gate tablet'),
        AssignRole::class => new AssignRole($context, 'usr_x', 'cashier', null),
        OpenDeviceSession::class => new OpenDeviceSession($context, 'usr_x', 'cashier'),
        default => new $class($context, []),
    };
}

beforeEach(function (): void {
    $this->seed([SitesSeeder::class, DemoUsersSeeder::class]);
});

it('applies the role matrix', function (string $commandClass, string $actorCode, ?string $siteCode, string $expected): void {
    $actor = User::query()->where('code', $actorCode)->firstOrFail();
    $siteId = $siteCode === null ? null : Site::query()->where('code', $siteCode)->firstOrFail()->id;
    $command = matrixCommand($commandClass, new CommandContext($siteId, $actor->id, null, CarbonImmutable::now()));

    $authorizer = app(Registry::class)->authorizerFor($command);
    expect($authorizer)->not->toBeNull();

    if ($expected === 'allowed') {
        $authorizer->authorise($command);
        expect(true)->toBeTrue();

        return;
    }

    try {
        $authorizer->authorise($command);
        $this->fail("{$actorCode} should be forbidden from {$commandClass}");
    } catch (Rejection $e) {
        expect($e->rejectionCode)->toBe(RejectionCode::Forbidden);
    }
})->with(fn (): array => roleMatrix());

it('has an authorizer and a matrix row for every registered command', function (): void {
    $registry = app(Registry::class);
    $covered = array_unique(array_column(roleMatrix(), 0));

    foreach ($registry->registeredCommands() as $command) {
        expect($registry->hasAuthorizer($command))->toBeTrue("{$command} has no authorizer")
            ->and($covered)->toContain($command);
    }
});

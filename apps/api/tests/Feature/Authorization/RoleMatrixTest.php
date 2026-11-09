<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Tanaad\Cash\Commands\CloseCashDay;
use Tanaad\Cash\Commands\CorrectCashEntry;
use Tanaad\Cash\Commands\RecordCashEntry;
use Tanaad\Cash\Commands\ReopenCashDay;
use Tanaad\Cash\Commands\SetCashOpening;
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
use Tanaad\Group\Commands\DecideException;
use Tanaad\Group\Commands\RaiseException;
use Tanaad\Group\Commands\SendBrief;
use Tanaad\Identity\Commands\AssignRole;
use Tanaad\Identity\Commands\OpenDeviceSession;
use Tanaad\Identity\Commands\RegisterDevice;
use Tanaad\Identity\Database\Seeders\DemoUsersSeeder;
use Tanaad\Identity\Models\User;
use Tanaad\People\Commands\ApproveSettlement;
use Tanaad\People\Commands\ArchivePerson;
use Tanaad\People\Commands\CancelSettlementRun;
use Tanaad\People\Commands\CreateSettlementRun;
use Tanaad\People\Commands\EndEngagement;
use Tanaad\People\Commands\GiveAdvance;
use Tanaad\People\Commands\MarkAttendance;
use Tanaad\People\Commands\ReactivatePerson;
use Tanaad\People\Commands\RecordPayout;
use Tanaad\People\Commands\RegisterPerson;
use Tanaad\People\Commands\RenewEngagement;
use Tanaad\People\Commands\StartEngagement;
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

    // Cash: the site's cashier or manager writes the book; only its manager reopens a day. Group roles only read.
    $cashWrite = ['u_cash_mats' => 'allowed', 'u_sm_mats' => 'allowed', 'u_cash_bags' => 'forbidden', 'u_sup_mats' => 'forbidden', 'u_admin' => 'forbidden', 'u_ceo1' => 'forbidden'];
    foreach ([RecordCashEntry::class, CorrectCashEntry::class, CloseCashDay::class] as $command) {
        $add($command, $cashWrite);
    }
    $add(ReopenCashDay::class, ['u_sm_mats' => 'allowed', 'u_cash_mats' => 'forbidden', 'u_sm_bags' => 'forbidden', 'u_admin' => 'forbidden', 'u_ceo1' => 'forbidden']);
    $add(SetCashOpening::class, ['u_cash_mats' => 'allowed', 'u_sm_mats' => 'allowed', 'u_ceo1' => 'allowed', 'u_cash_bags' => 'forbidden', 'u_sup_mats' => 'forbidden', 'u_admin' => 'forbidden']);

    // People: supervisors mark attendance and register casuals at their own site.
    $floor = ['u_admin' => 'allowed', 'u_sm_mats' => 'allowed', 'u_sup_mats' => 'allowed', 'u_sup_bags' => 'forbidden', 'u_sm_bags' => 'forbidden', 'u_cash_mats' => 'forbidden', 'u_ceo1' => 'forbidden'];
    $add(MarkAttendance::class, $floor);
    $add(RegisterPerson::class, $floor);
    $managers = [...$floor, 'u_sup_mats' => 'forbidden'];
    foreach ([StartEngagement::class, RenewEngagement::class, EndEngagement::class] as $command) {
        $add($command, $managers);
    }
    foreach ([ArchivePerson::class, ReactivatePerson::class] as $command) {
        $add($command, [...$managers, 'u_ceo1' => 'allowed']);
    }
    // Settlement: managers create; managers or the CEO approve (never the creator, checked by the handler);
    // the site's cashier also pays out and gives advances.
    $add(CreateSettlementRun::class, $managers);
    foreach ([ApproveSettlement::class, CancelSettlementRun::class] as $command) {
        $add($command, [...$managers, 'u_ceo1' => 'allowed']);
    }
    foreach ([RecordPayout::class, GiveAdvance::class] as $command) {
        $add($command, [...$managers, 'u_cash_mats' => 'allowed', 'u_cash_bags' => 'forbidden']);
    }

    // Group: managers, supervisors and group roles raise; group roles and the site's manager decide.
    $add(RaiseException::class, ['u_ceo1' => 'allowed', 'u_admin' => 'allowed', 'u_sm_mats' => 'allowed', 'u_sup_mats' => 'allowed', 'u_sm_bags' => 'forbidden', 'u_sup_bags' => 'forbidden', 'u_cash_mats' => 'forbidden']);
    $add(DecideException::class, ['u_ceo1' => 'allowed', 'u_admin' => 'allowed', 'u_sm_mats' => 'allowed', 'u_sm_bags' => 'forbidden', 'u_sup_mats' => 'forbidden', 'u_cash_mats' => 'forbidden']);

    // Briefs are group-wide: CEO and group admin only.
    $add(SendBrief::class, ['u_ceo1' => 'allowed', 'u_admin' => 'allowed', 'u_sm_mats' => 'forbidden', 'u_sup_mats' => 'forbidden', 'u_cash_mats' => 'forbidden'], null);

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

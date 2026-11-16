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
use Tanaad\Dispatch\Commands\ConfirmDelivery;
use Tanaad\Dispatch\Commands\OverrideGate;
use Tanaad\Dispatch\Commands\RecordGateWeight;
use Tanaad\Dispatch\Commands\ScanBundle;
use Tanaad\Dispatch\Commands\StartDispatch;
use Tanaad\Dispatch\Commands\UnscanBundle;
use Tanaad\Group\Commands\DecideException;
use Tanaad\Group\Commands\RaiseException;
use Tanaad\Group\Commands\SendBrief;
use Tanaad\Identity\Commands\AssignRole;
use Tanaad\Identity\Commands\OpenDeviceSession;
use Tanaad\Identity\Commands\RegisterDevice;
use Tanaad\Identity\Database\Seeders\DemoUsersSeeder;
use Tanaad\Identity\Models\User;
use Tanaad\Labels\Commands\ConsumeLabel;
use Tanaad\Labels\Commands\CreateLabel;
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
use Tanaad\Production\Commands\CloseShift;
use Tanaad\Production\Commands\RecordBatch;
use Tanaad\Production\Commands\RecordProductionEntry;
use Tanaad\Production\Commands\WeighWaste;
use Tanaad\Purchases\Commands\ChangePurchase;
use Tanaad\Purchases\Commands\ReceivePurchase;
use Tanaad\Purchases\Commands\RecordPurchase;
use Tanaad\Recycling\Commands\PayCollector;
use Tanaad\Recycling\Commands\RecordCollectorIntake;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\Registry;
use Tanaad\Shared\Ledger\Rejection;
use Tanaad\Shared\Ledger\RejectionCode;
use Tanaad\Spares\Commands\IssuePart;
use Tanaad\Spares\Commands\ReceivePart;
use Tanaad\Spares\Commands\ReportBreakdown;
use Tanaad\Spares\Commands\ResolveBreakdown;
use Tanaad\Spares\Commands\SavePart;

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

    // Production: the site's supervisor or manager records; group roles, cashiers and other sites' staff may not.
    $production = ['u_sup_mats' => 'allowed', 'u_sm_mats' => 'allowed', 'u_sup_bags' => 'forbidden', 'u_sm_bags' => 'forbidden', 'u_store_mats' => 'forbidden', 'u_cash_mats' => 'forbidden', 'u_admin' => 'forbidden', 'u_ceo1' => 'forbidden'];
    foreach ([RecordProductionEntry::class, RecordBatch::class, CloseShift::class, WeighWaste::class] as $command) {
        $add($command, $production);
    }

    // Labels: the site's supervisor, storekeeper or manager print and consume.
    $labels = ['u_sup_mats' => 'allowed', 'u_store_mats' => 'allowed', 'u_sm_mats' => 'allowed', 'u_store_bags' => 'forbidden', 'u_sup_bags' => 'forbidden', 'u_cash_mats' => 'forbidden', 'u_admin' => 'forbidden', 'u_ceo1' => 'forbidden'];
    foreach ([CreateLabel::class, ConsumeLabel::class] as $command) {
        $add($command, $labels);
    }

    // Recycling: the scale crew records a delivery; only the cashier or manager pays the collector.
    $add(RecordCollectorIntake::class, ['u_sup_rec' => 'allowed', 'u_sm_rec' => 'allowed', 'u_store_rec' => 'allowed', 'u_cash_rec' => 'allowed', 'u_mech' => 'forbidden', 'u_sales_rec' => 'forbidden', 'u_cash_mats' => 'forbidden', 'u_sm_mats' => 'forbidden', 'u_admin' => 'forbidden', 'u_ceo1' => 'forbidden'], 'recycling');
    $add(PayCollector::class, ['u_cash_rec' => 'allowed', 'u_sm_rec' => 'allowed', 'u_sup_rec' => 'forbidden', 'u_store_rec' => 'forbidden', 'u_cash_mats' => 'forbidden', 'u_sm_mats' => 'forbidden', 'u_admin' => 'forbidden', 'u_ceo1' => 'forbidden'], 'recycling');

    // Purchases: the site's manager or storekeeper, or a group role, orders and edits (cashiers pay out through the cash book); its manager or storekeeper receives.
    foreach ([RecordPurchase::class, ChangePurchase::class] as $command) {
        $add($command, ['u_sm_mats' => 'allowed', 'u_store_mats' => 'allowed', 'u_cash_mats' => 'forbidden', 'u_sup_mats' => 'forbidden', 'u_sm_bags' => 'forbidden', 'u_store_bags' => 'forbidden', 'u_cash_bags' => 'forbidden', 'u_admin' => 'allowed', 'u_ceo1' => 'allowed']);
    }
    $add(ReceivePurchase::class, ['u_sm_mats' => 'allowed', 'u_store_mats' => 'allowed', 'u_cash_mats' => 'forbidden', 'u_sup_mats' => 'forbidden', 'u_sm_bags' => 'forbidden', 'u_store_bags' => 'forbidden', 'u_admin' => 'forbidden', 'u_ceo1' => 'forbidden']);

    // Spares: the site manager keeps the catalogue and receives; the storekeeper receives and issues (as does the
    // supervisor); mechanic, supervisor and manager report and resolve breakdowns.
    $add(SavePart::class, ['u_admin' => 'allowed', 'u_sm_mats' => 'allowed', 'u_store_mats' => 'forbidden', 'u_mech' => 'forbidden', 'u_sm_bags' => 'forbidden', 'u_ceo1' => 'forbidden']);
    $add(ReceivePart::class, ['u_store_mats' => 'allowed', 'u_sm_mats' => 'allowed', 'u_mech' => 'forbidden', 'u_sup_mats' => 'forbidden', 'u_store_bags' => 'forbidden', 'u_admin' => 'forbidden', 'u_ceo1' => 'forbidden']);
    $add(IssuePart::class, ['u_store_mats' => 'allowed', 'u_sup_mats' => 'allowed', 'u_mech' => 'forbidden', 'u_sm_mats' => 'forbidden', 'u_store_bags' => 'forbidden', 'u_sup_bags' => 'forbidden', 'u_admin' => 'forbidden', 'u_ceo1' => 'forbidden']);
    $breakdown = ['u_mech' => 'allowed', 'u_sup_mats' => 'allowed', 'u_sm_mats' => 'allowed', 'u_store_mats' => 'forbidden', 'u_cash_mats' => 'forbidden', 'u_sup_bags' => 'forbidden', 'u_sm_bags' => 'forbidden', 'u_admin' => 'forbidden', 'u_ceo1' => 'forbidden'];
    foreach ([ReportBreakdown::class, ResolveBreakdown::class] as $command) {
        $add($command, $breakdown);
    }

    // Stock: the site's storekeeper, supervisor or manager writes; only its manager reopens a count.
    $stock = ['u_store_mats' => 'allowed', 'u_sup_mats' => 'allowed', 'u_sm_mats' => 'allowed', 'u_store_bags' => 'forbidden', 'u_cash_mats' => 'forbidden', 'u_admin' => 'forbidden', 'u_ceo1' => 'forbidden'];
    foreach ([Tanaad\Stock\Commands\ReceiveStock::class, Tanaad\Stock\Commands\MoveStock::class, Tanaad\Stock\Commands\ScrapLabel::class, Tanaad\Stock\Commands\SendTransfer::class, Tanaad\Stock\Commands\ReceiveTransfer::class, Tanaad\Stock\Commands\RecordStockCount::class] as $command) {
        $add($command, $stock);
    }
    $add(Tanaad\Stock\Commands\ReopenStockCount::class, ['u_sm_mats' => 'allowed', 'u_store_mats' => 'forbidden', 'u_sup_mats' => 'forbidden', 'u_sm_bags' => 'forbidden', 'u_admin' => 'forbidden', 'u_ceo1' => 'forbidden']);

    // Dispatch: the site's storekeeper, gate or manager load and weigh; only its manager overrides (SoD in the handler); drivers may confirm delivery.
    $dispatch = ['u_store_mats' => 'allowed', 'u_sm_mats' => 'allowed', 'u_store_bags' => 'forbidden', 'u_sup_mats' => 'forbidden', 'u_cash_mats' => 'forbidden', 'u_admin' => 'forbidden', 'u_ceo1' => 'forbidden'];
    foreach ([StartDispatch::class, ScanBundle::class, UnscanBundle::class, RecordGateWeight::class, ConfirmDelivery::class] as $command) {
        $add($command, $dispatch);
    }
    $add(OverrideGate::class, ['u_sm_mats' => 'allowed', 'u_store_mats' => 'forbidden', 'u_sm_bags' => 'forbidden', 'u_sup_mats' => 'forbidden', 'u_admin' => 'forbidden', 'u_ceo1' => 'forbidden']);

    // Orders: the site's sales or manager (and group roles) keep clients and take orders (credit terms: CEO/group admin only, tested below); cash roles confirm cash;
    // only the CEO or group admin decide on the credit desk; sales, cashier, manager or group roles invoice and record payments.
    $salesWrite = ['u_sales_mats' => 'allowed', 'u_sm_mats' => 'allowed', 'u_ceo1' => 'allowed', 'u_admin' => 'allowed', 'u_sales_bags' => 'forbidden', 'u_sm_bags' => 'forbidden', 'u_cash_mats' => 'forbidden', 'u_store_mats' => 'forbidden'];
    foreach ([Tanaad\Orders\Commands\RegisterClient::class, Tanaad\Orders\Commands\UpdateClient::class, Tanaad\Orders\Commands\CreateOrder::class] as $command) {
        $add($command, $salesWrite);
    }
    $add(Tanaad\Orders\Commands\ConfirmOrderCash::class, ['u_cash_mats' => 'allowed', 'u_sm_mats' => 'allowed', 'u_ceo1' => 'allowed', 'u_admin' => 'allowed', 'u_sales_mats' => 'forbidden', 'u_cash_bags' => 'forbidden', 'u_store_mats' => 'forbidden']);
    foreach ([Tanaad\Orders\Commands\ApproveOrder::class, Tanaad\Orders\Commands\HoldOrder::class] as $command) {
        $add($command, ['u_ceo1' => 'allowed', 'u_admin' => 'allowed', 'u_sm_mats' => 'forbidden', 'u_sales_mats' => 'forbidden', 'u_cash_mats' => 'forbidden']);
    }
    foreach ([Tanaad\Orders\Commands\IssueInvoice::class, Tanaad\Orders\Commands\RecordPayment::class] as $command) {
        $add($command, ['u_sales_mats' => 'allowed', 'u_cash_mats' => 'allowed', 'u_sm_mats' => 'allowed', 'u_ceo1' => 'allowed', 'u_sales_bags' => 'forbidden', 'u_cash_bags' => 'forbidden', 'u_store_mats' => 'forbidden', 'u_sup_mats' => 'forbidden']);
    }

    // Messaging: the group admin keeps recipients; queueing, delivery and failure are system-only (no signed-in user may issue them).
    $add(Tanaad\Messaging\Commands\SaveRecipient::class, ['u_admin' => 'allowed', 'u_ceo1' => 'forbidden', 'u_sm_mats' => 'forbidden', 'u_cash_mats' => 'forbidden'], null);
    foreach ([Tanaad\Messaging\Commands\QueueMessage::class, Tanaad\Messaging\Commands\RecordDelivery::class, Tanaad\Messaging\Commands\RecordFailure::class] as $command) {
        $add($command, ['u_admin' => 'forbidden', 'u_ceo1' => 'forbidden', 'u_sm_mats' => 'forbidden', 'u_cash_mats' => 'forbidden'], null);
    }

    // Costing: the site's manager, the CEO or the group admin close and reopen periods; the mapping is the manager's or group admin's.
    foreach ([Tanaad\Costing\Commands\ClosePeriod::class, Tanaad\Costing\Commands\ReopenPeriod::class] as $command) {
        $add($command, ['u_sm_mats' => 'allowed', 'u_ceo1' => 'allowed', 'u_admin' => 'allowed', 'u_sm_bags' => 'forbidden', 'u_cash_mats' => 'forbidden', 'u_sup_mats' => 'forbidden']);
    }
    $add(Tanaad\Costing\Commands\SaveCostMapping::class, ['u_sm_mats' => 'allowed', 'u_admin' => 'allowed', 'u_ceo1' => 'forbidden', 'u_sm_bags' => 'forbidden', 'u_cash_mats' => 'forbidden']);

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

it('lets only the CEO or group admin register a client with credit terms', function (string $actorCode, string $expected): void {
    $actor = User::query()->where('code', $actorCode)->firstOrFail();
    $siteId = Site::query()->where('code', 'mats')->firstOrFail()->id;
    $command = new Tanaad\Orders\Commands\RegisterClient(new CommandContext($siteId, $actor->id, null, CarbonImmutable::now()), ['credit_limit' => 1000, 'credit_days' => 30]);
    $authorizer = app(Registry::class)->authorizerFor($command);
    if ($expected === 'allowed') {
        $authorizer?->authorise($command);
        expect(true)->toBeTrue();

        return;
    }
    expect(fn () => $authorizer?->authorise($command))->toThrow(Rejection::class);
})->with([['u_ceo1', 'allowed'], ['u_admin', 'allowed'], ['u_sales_mats', 'forbidden'], ['u_sm_mats', 'forbidden']]);

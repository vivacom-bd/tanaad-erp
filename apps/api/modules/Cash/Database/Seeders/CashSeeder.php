<?php

declare(strict_types=1);

namespace Tanaad\Cash\Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Ramsey\Uuid\Uuid;
use Tanaad\Cash\Commands\CloseCashDay;
use Tanaad\Cash\Commands\RecordCashEntry;
use Tanaad\Cash\Commands\SetCashOpening;
use Tanaad\Config\Models\Machine;
use Tanaad\Config\Models\Product;
use Tanaad\Config\Models\Site;
use Tanaad\Identity\Models\User;
use Tanaad\Shared\Ledger\Command;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\CommandContext;

/**
 * The prototype's demo cash book (database/seed-data/cash.json, exported by
 * `pnpm seed:export`), replayed as cash entries and day closes in time order, each by the
 * cashier in the data. Ids are uuid5 of the prototype ids, so a second run is all
 * duplicates. The server derives an opening from the previous counted close, so cash.opening_set
 * is sent only where the prototype's opening differs: each site's first day (the cash already in
 * the box) and any day that does not follow a counted close.
 *
 * The prototype's random history overdraws one day (a closed count below zero). A cash box
 * cannot hold negative cash and the close command rejects it, so that day is closed at 0 with a
 * receipt for the shortfall, and the next day carries an equal payment out. Both are cash-neutral
 * across the two days, so every later opening, expected closing and variance matches the prototype.
 * Run after ConfigSeeder (machines) and before SettlementSeeder (which pays into open cash days).
 *
 * $site and $fromDay take a slice for tests.
 */
final class CashSeeder extends Seeder
{
    public const string NAMESPACE = '3b8e0f6c-41d2-5a7e-b3c9-2e6a9d104f57';

    public function __construct(private readonly ?string $site = null, private readonly ?string $fromDay = null) {}

    public static function id(string $name): string
    {
        return Uuid::uuid5(self::NAMESPACE, $name)->toString();
    }

    /** @return array{today: string, rows: array{days: list<array<string, mixed>>, entries: list<array<string, mixed>>}} */
    public static function data(): array
    {
        /** @var array{today: string, rows: array{days: list<array<string, mixed>>, entries: list<array<string, mixed>>}} $data */
        $data = json_decode((string) file_get_contents(database_path('seed-data/cash.json')), true, flags: JSON_THROW_ON_ERROR);

        return $data;
    }

    public function run(CommandBus $bus): void
    {
        $sites = Site::query()->pluck('id', 'code')->map(fn ($id): string => (string) $id)->all();
        $users = User::query()->pluck('id', 'code')->map(fn ($id): string => (string) $id)->all();
        $machines = Machine::query()->pluck('id', 'code_key')->map(fn ($id): string => (string) $id)->all();
        $products = Product::query()->pluck('id', 'code')->map(fn ($id): string => (string) $id)->all();
        $data = self::data()['rows'];
        $keep = fn (array $r): bool => ($this->site === null || $r['site'] === $this->site) && ($this->fromDay === null || $r['day'] >= $this->fromDay);
        $ctx = fn (array $r, string $by, string $at, string $source): CommandContext => new CommandContext(
            $sites[(string) $r['site']], $users[$by], null, CarbonImmutable::parse($at)->setTimezone('Africa/Kampala'), self::id('event:'.$source),
        );

        /** @var list<array{string, Command}> $out */
        $out = [];
        foreach (array_filter($data['entries'], $keep) as $e) {
            $out[] = [(string) $e['at'], new RecordCashEntry($ctx($e, (string) $e['by'], (string) $e['at'], 'entry:'.$e['id']), [
                'entry_id' => self::id('entry:'.$e['id']), 'day' => $e['day'], 'direction' => $e['direction'], 'category' => $e['category'],
                'subcategory' => $e['subcategory'], 'amount' => $e['amount'], 'currency' => $e['currency'], 'fx_reference' => $e['fx_reference'],
                'machine_id' => $e['machine'] === null ? null : $machines[(string) $e['machine']],
                'product_id' => $e['product'] === null ? null : $products[(string) $e['product']],
                'payee' => $e['payee'], 'note' => $e['note'], 'has_receipt_photo' => $e['has_receipt_photo'],
            ])];
        }
        $bySite = [];
        foreach ($data['days'] as $d) {
            $bySite[(string) $d['site']][] = $d;
        }
        foreach ($bySite as $days) {
            usort($days, fn (array $x, array $y): int => strcmp((string) $x['day'], (string) $y['day']));
            $first = true;
            $previous = null;
            $overdrawn = null;
            foreach ($days as $d) {
                $day = (string) $d['day'];
                $kept = $keep($d);
                $entries = array_values(array_filter($data['entries'], fn (array $e): bool => $e['cash_day_id'] === $d['id']));
                $cashier = (string) ($entries[0]['by'] ?? $d['signed_by'] ?? 'u_cash_'.$d['site']);
                if ($kept && ($first || (float) $d['opening'] !== (float) ($previous['counted'] ?? 0))) {
                    $at = $day.'T06:00:00+03:00';
                    $out[] = [$at, new SetCashOpening($ctx($d, $cashier, $at, 'opening:'.$d['id']), ['day' => $day, 'amount' => $d['opening']])];
                }
                if ($kept) {
                    $first = false;
                }
                if ($kept && $overdrawn !== null) {
                    $at = $day.'T07:45:00+03:00';
                    $out[] = [$at, new RecordCashEntry($ctx($d, $cashier, $at, 'entry:float-out:'.$d['id']), [
                        'entry_id' => self::id('entry:float-out:'.$d['id']), 'day' => $day, 'direction' => 'out', 'category' => 'other', 'subcategory' => null,
                        'amount' => $overdrawn, 'currency' => $d['currency'], 'fx_reference' => null, 'machine_id' => null, 'product_id' => null,
                        'payee' => 'Float returned to the head-office advance', 'note' => 'Repays the receipt that covered the overdrawn previous day.', 'has_receipt_photo' => false,
                    ])];
                }
                $overdrawn = null;
                if ($d['status'] === 'closed' && $d['counted'] !== null) {
                    $counted = $d['counted'];
                    if ($kept && $counted < 0) {
                        $overdrawn = -$counted;
                        $at = $day.'T17:55:00+03:00';
                        $out[] = [$at, new RecordCashEntry($ctx($d, $cashier, $at, 'entry:float-in:'.$d['id']), [
                            'entry_id' => self::id('entry:float-in:'.$d['id']), 'day' => $day, 'direction' => 'in', 'category' => 'other', 'subcategory' => null,
                            'amount' => $overdrawn, 'currency' => $d['currency'], 'fx_reference' => null, 'machine_id' => null, 'product_id' => null,
                            'payee' => 'Float from the head-office advance', 'note' => 'Covers the day\'s payments that exceeded the cash in the box.', 'has_receipt_photo' => false,
                        ])];
                        $counted = 0;
                    }
                    if ($kept) {
                        $out[] = [(string) $d['closed_at'], new CloseCashDay($ctx($d, (string) $d['signed_by'], (string) $d['closed_at'], 'close:'.$d['id']), [
                            'day' => $day, 'counted' => $counted, 'denominations' => (array) $d['denominations'],
                        ])];
                    }
                }
                $previous = $d;
            }
        }
        usort($out, fn (array $x, array $y): int => strcmp($x[0], $y[0]));
        foreach ($out as [, $command]) {
            $bus->dispatch($command);
        }
    }
}

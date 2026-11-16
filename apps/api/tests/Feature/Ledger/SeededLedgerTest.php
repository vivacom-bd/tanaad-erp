<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tanaad\Shared\Ledger\Replay;

/** @return array<string, list<array<string, mixed>>> the read tables of the mixed modules, without wall-clock columns */
function seededRows(): array
{
    $out = [];
    foreach (['cash_entries', 'cash_days', 'orders', 'invoices', 'payments', 'clients', 'costing_revenue_lines', 'costing_cost_lines', 'costing_periods', 'exceptions'] as $table) {
        $out[$table] = DB::table($table)->orderBy('id')->get()->map(function ($r): array {
            $a = (array) $r;
            unset($a['created_at'], $a['updated_at']);

            return $a;
        })->all();
    }

    return $out;
}

it('verifies the seeded demo ledger: checksums of every event type and a full replay', function (): void {
    $this->seed();
    expect(DB::table('events')->whereNull('checksum')->count())->toBe(0)
        ->and(DB::table('events')->where('type', 'like', 'orders.%')->exists())->toBeTrue()
        ->and(DB::table('events')->where('type', 'like', 'costing.%')->exists())->toBeTrue();

    $code = Artisan::call('ledger:verify', ['--replay' => true]);
    $out = Artisan::output();
    // Every event type checksums and replays exactly, and received_at is monotonic: a fully clean verify.
    $failures = array_values(array_filter(explode("\n", trim($out)), fn (string $l): bool => ! str_starts_with($l, 'Scanned') && ! str_starts_with($l, 'Replay compared')
        && ! str_starts_with($l, 'Ledger verified') && ! str_contains($l, 'seq gap') && trim($l) !== ''));
    expect($code)->toBe(0, $out)->and($out)->not->toContain('received_at');
    expect($out)->toContain('Replay compared')->and($failures)->toBe([], $out);

    $before = seededRows();
    app(Replay::class)->rebuild();
    expect(seededRows())->toEqual($before);
});

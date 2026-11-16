<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tanaad\Config\Database\Seeders\SitesSeeder;
use Tanaad\Config\Models\Site;
use Tanaad\Identity\Database\Seeders\DemoUsersSeeder;
use Tanaad\Identity\Models\User;

beforeEach(function (): void {
    $this->seed([SitesSeeder::class, DemoUsersSeeder::class]);
    $this->user = fn (string $code): User => User::query()->where('code', $code)->firstOrFail();
    $token = fn (string $code): string => ($this->user)($code)->createToken('office', ['user'])->plainTextToken;
    $this->store = $token('u_store_mats');
    $this->otherStore = $token('u_store_bags');
    $this->manager = $token('u_sm_mats');
    $this->otherManager = $token('u_sm_bags');
    $this->ceo = $token('u_ceo1');
    $this->cashier = $token('u_cash_mats');
    $this->sup = $token('u_sup_mats');
    $this->mats = (string) Site::query()->where('code', 'mats')->value('id');
    $now = now();
    DB::table('company_profiles')->insert(['id' => (string) Str::uuid7(), 'site_id' => $this->mats, 'address' => 'Plot 4, Kampala', 'phone' => '+256414000000',
        'tin' => '1000012345', 'bank_details' => 'Stanbic 0123', 'vat_rate_pct' => 18, 'created_at' => $now, 'updated_at' => $now]);
});

function docDispatch(Tests\TestCase $t, bool $note = true): string
{
    $now = now();
    $product = (string) Str::uuid7();
    DB::table('products')->insert(['id' => $product, 'site_id' => $t->mats, 'code' => 'p_mat', 'internal_name' => 'Mat 38', 'kind' => 'mat', 'bundle_pieces' => 50,
        'std_bundle_weight_kg' => 38, 'weight_tolerance_pct' => 2, 'weight_status' => 'confirmed', 'active' => true, 'seq' => 0, 'created_at' => $now, 'updated_at' => $now]);
    $id = (string) Str::uuid7();
    DB::table('dispatches')->insert([
        'id' => $id, 'site_id' => $t->mats, 'order_ref' => 'SO-2301', 'client_name' => 'Kigali Traders', 'lines' => json_encode([['product_id' => $product, 'bundles' => 2, 'std_bundle_weight_kg' => 38]]),
        'ordered_bundles' => 2, 'expected_kg' => 76, 'driver' => 'Okello', 'vehicle' => 'UBA 123X', 'gate_status' => 'pass', 'gate_kg' => 76.4, 'variance_pct' => 0.53,
        'received_by' => 'J. Mugisha', 'bundles_received' => 2, 'discrepancy' => 0, 'signature_hash' => str_repeat('a', 64), 'photo_hash' => str_repeat('b', 64),
        'delivered_at' => $now, 'event_id' => (string) Str::uuid7(), 'at' => $now, 'seq' => 1, 'created_at' => $now, 'updated_at' => $now,
    ]);
    foreach (['MAT-000001', 'MAT-000002'] as $code) {
        DB::table('dispatch_bundles')->insert(['dispatch_id' => $id, 'site_id' => $t->mats, 'label_id' => (string) Str::uuid7(), 'code' => $code, 'product_id' => $product,
            'event_id' => (string) Str::uuid7(), 'at' => $now, 'seq' => 1]);
    }
    if ($note) {
        DB::table('delivery_notes')->insert(['dispatch_id' => $id, 'site_id' => $t->mats, 'number' => 'DN-4817', 'via' => 'pass', 'event_id' => (string) Str::uuid7(), 'at' => $now, 'seq' => 1]);
    }

    return $id;
}

function docPayslip(Tests\TestCase $t, ?string $personUser = null): string
{
    $now = now();
    $person = (string) Str::uuid7();
    DB::table('people')->insert(['id' => $person, 'site_id' => $t->mats, 'number' => 'W-001', 'full_name' => 'Aisha Nakato', 'status' => 'active', 'joined_on' => '2026-01-01', 'seq' => 1, 'created_at' => $now, 'updated_at' => $now]);
    if ($personUser !== null) {
        ($t->user)($personUser)->update(['person_id' => $person]);
    }
    $run = (string) Str::uuid7();
    $common = ['site_id' => $t->mats, 'seq' => 1, 'created_at' => $now, 'updated_at' => $now];
    DB::table('settlement_runs')->insert(['id' => $run, ...$common, 'kind' => 'monthly', 'period_from' => '2026-11-01', 'period_to' => '2026-11-30', 'currency' => 'UGX', 'status' => 'approved',
        'standard_day_hours' => 8, 'month_working_days' => 26, 'line_count' => 1, 'total_units' => 26, 'total_gross' => 520000, 'total_overtime' => 0, 'total_bonus' => 0, 'total_advances' => 20000, 'total_net' => 500000, 'created_event_id' => (string) Str::uuid7()]);
    $line = (string) Str::uuid7();
    DB::table('settlement_lines')->insert(['id' => $line, ...$common, 'settlement_run_id' => $run, 'person_id' => $person, 'engagement_id' => (string) Str::uuid7(), 'period_from' => '2026-11-01', 'period_to' => '2026-11-30',
        'rate_basis' => 'daily', 'rate' => 20000, 'units' => 26, 'overtime_hours' => 0, 'gross' => 520000, 'overtime' => 0, 'bonus' => 0, 'advances_deducted' => 20000, 'net' => 500000,
        'deductions' => json_encode([['advance_id' => (string) Str::uuid7(), 'amount' => 20000]]), 'status' => 'unpaid']);

    return $line;
}

it('renders a delivery note as structured data for the site and group roles', function (): void {
    $id = docDispatch($this);
    $doc = $this->withToken($this->store)->postJson("/api/v1/documents/delivery_note/{$id}/render")->assertOk()->json('document');
    expect($doc['kind'])->toBe('delivery_note')->and($doc['number'])->toBe('DN-4817')->and($doc['order_ref'])->toBe('SO-2301')
        ->and($doc['company']['tin'])->toBe('1000012345')->and($doc['company']['address'])->toBe('Plot 4, Kampala')->and($doc['site']['code'])->toBe('mats')
        ->and($doc['lines'])->toHaveCount(1)->and($doc['lines'][0]['product_name'])->toBe('Mat 38')->and($doc['lines'][0]['bundles'])->toBe(2)
        ->and($doc['bundle_codes'])->toBe(['MAT-000001', 'MAT-000002'])->and($doc['totals']['scanned_bundles'])->toBe(2)
        ->and($doc['receipt']['signature_hash'])->toBe(str_repeat('a', 64))->and($doc['content_hash'])->toHaveLength(64);
    // The same facts hash the same.
    expect($this->withToken($this->manager)->postJson("/api/v1/documents/delivery_note/{$id}/render")->json('document.content_hash'))->toBe($doc['content_hash']);
    $this->withToken($this->ceo)->postJson("/api/v1/documents/delivery_note/{$id}/render")->assertOk();
});

it('keeps delivery notes to the right site and role, and to issued notes', function (): void {
    $id = docDispatch($this);
    $this->withToken($this->otherStore)->postJson("/api/v1/documents/delivery_note/{$id}/render")->assertForbidden();
    $this->withToken($this->otherManager)->postJson("/api/v1/documents/delivery_note/{$id}/render")->assertForbidden();
    $this->withToken($this->cashier)->postJson("/api/v1/documents/delivery_note/{$id}/render")->assertForbidden();
    $this->withToken($this->store)->postJson('/api/v1/documents/delivery_note/'.Str::uuid7().'/render')->assertNotFound();
    DB::table('delivery_notes')->delete();
    $this->withToken($this->store)->postJson("/api/v1/documents/delivery_note/{$id}/render")->assertStatus(409);
    $this->flushHeaders()->postJson("/api/v1/documents/delivery_note/{$id}/render")->assertUnauthorized();
});

it('renders a payslip for the manager, the CEO and the worker only', function (): void {
    $line = docPayslip($this, 'u_sup_mats');
    $doc = $this->withToken($this->manager)->postJson("/api/v1/documents/payslip/{$line}/render")->assertOk()->json('document');
    expect($doc['worker']['name'])->toBe('Aisha Nakato')->and($doc['currency'])->toBe('UGX')->and($doc['period'])->toBe(['from' => '2026-11-01', 'to' => '2026-11-30'])
        ->and((float) $doc['totals']['net'])->toBe(500000.0)->and((float) $doc['totals']['advances_deducted'])->toBe(20000.0)->and($doc['deductions'])->toHaveCount(1)
        ->and($doc['payout'])->toBeNull();
    $this->withToken($this->ceo)->postJson("/api/v1/documents/payslip/{$line}/render")->assertOk();
    $this->withToken($this->sup)->postJson("/api/v1/documents/payslip/{$line}/render")->assertOk();   // the worker's own payslip
    $this->withToken($this->cashier)->postJson("/api/v1/documents/payslip/{$line}/render")->assertForbidden();
    $this->withToken($this->otherManager)->postJson("/api/v1/documents/payslip/{$line}/render")->assertForbidden();
    $this->withToken($this->manager)->postJson('/api/v1/documents/payslip/'.Str::uuid7().'/render')->assertNotFound();
});

it('renders an invoice with its order lines, and a client statement, from the Orders tables', function (): void {
    $now = now();
    $client = (string) Str::uuid7();
    $order = (string) Str::uuid7();
    $inv = (string) Str::uuid7();
    $product = (string) Str::uuid7();
    DB::table('products')->insert(['id' => $product, 'site_id' => $this->mats, 'code' => 'p_mat', 'internal_name' => 'Mat 38', 'kind' => 'mat', 'bundle_pieces' => 50,
        'std_bundle_weight_kg' => 38, 'weight_tolerance_pct' => 2, 'weight_status' => 'confirmed', 'active' => true, 'seq' => 0, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('clients')->insert(['id' => $client, 'site_id' => $this->mats, 'name' => 'Kigali Traders', 'country' => 'Rwanda', 'channel' => 'export', 'currency_pref' => 'USD',
        'event_id' => (string) Str::uuid7(), 'seq' => 1, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('orders')->insert(['id' => $order, 'site_id' => $this->mats, 'number' => 'SO-1', 'client_id' => $client, 'client_name' => 'Kigali Traders', 'channel' => 'export',
        'status' => 'invoiced', 'currency' => 'USD', 'total' => 100, 'lines' => json_encode([['product_id' => $product, 'bundles' => 2, 'amount' => 100]]), 'day' => '2026-11-10',
        'at' => $now, 'event_id' => (string) Str::uuid7(), 'seq' => 1, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('invoices')->insert(['id' => $inv, 'site_id' => $this->mats, 'order_id' => $order, 'order_number' => 'SO-1', 'client_id' => $client, 'number' => 'INV-00001',
        'total' => 100, 'currency' => 'USD', 'paid_amount' => 40, 'balance' => 60, 'issued_day' => '2026-11-10', 'issued_at' => $now, 'event_id' => (string) Str::uuid7(), 'seq' => 1, 'created_at' => $now, 'updated_at' => $now]);
    $doc = $this->withToken($this->manager)->postJson("/api/v1/documents/invoice/{$inv}/render")->assertOk()->json('document');
    expect($doc['invoice']['number'])->toBe('INV-00001')->and($doc['lines'])->toHaveCount(1)->and($doc['lines'][0]['product_name'])->toBe('Mat 38')->and($doc['invoice'])->not->toHaveKeys(['event_id', 'seq']);
    $st = $this->withToken($this->manager)->postJson("/api/v1/documents/statement/{$client}/render")->assertOk()->json('document');
    expect($st['client_name'])->toBe('Kigali Traders')->and($st['invoices'])->toHaveCount(1)->and($st['invoices'][0])->not->toHaveKeys(['event_id', 'seq']);
    $this->withToken($this->otherManager)->postJson("/api/v1/documents/invoice/{$inv}/render")->assertForbidden();
});

it('answers 404 for unknown kinds and unknown invoices and statements', function (): void {
    $this->withToken($this->ceo)->postJson('/api/v1/documents/receipt/'.Str::uuid7().'/render')->assertNotFound();
    $this->withToken($this->ceo)->postJson('/api/v1/documents/invoice/'.Str::uuid7().'/render')->assertNotFound();
    $this->withToken($this->ceo)->postJson('/api/v1/documents/statement/'.Str::uuid7().'/render')->assertNotFound();
});

it('answers 501 for PDF whether the flag is off or the package is missing', function (): void {
    $id = docDispatch($this);
    $this->withToken($this->store)->postJson("/api/v1/documents/delivery_note/{$id}/render", ['format' => 'pdf'])->assertStatus(501);
    config(['tanaad.documents.pdf' => true]);
    $this->withToken($this->store)->postJson("/api/v1/documents/delivery_note/{$id}/render?format=pdf")->assertStatus(501);
    $this->withToken($this->store)->postJson("/api/v1/documents/delivery_note/{$id}/render")->assertOk();
});

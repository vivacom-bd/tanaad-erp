<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tanaad\Config\Database\Seeders\SitesSeeder;
use Tanaad\Config\Models\Site;
use Tanaad\Identity\Database\Seeders\DemoUsersSeeder;
use Tanaad\Identity\Models\User;
use Tanaad\Shared\Ledger\NumberSeries;
use Tanaad\Sync\Snapshots;
use Tests\Fixtures\Notes\NotesFixture;

beforeEach(function (): void {
    // install first: creating the table commits the test transaction (MySQL DDL), which would drop seeded rows
    NotesFixture::install();
    $this->seed([SitesSeeder::class, DemoUsersSeeder::class]);
    app(Snapshots::class)->register('test_notes', 'test_notes');
    $this->mats = Site::query()->where('code', 'mats')->firstOrFail();
    $this->bags = Site::query()->where('code', 'bags')->firstOrFail();
    app(NumberSeries::class)->define($this->mats->id, 'note', 'NT');
    $this->token = User::query()->where('code', 'u_cash_mats')->firstOrFail()->createToken('office', ['user'])->plainTextToken;
});

function note(array $over = []): array
{
    return [
        'id' => (string) Str::uuid7(),
        'type' => 'notes.note_added',
        'occurred_at' => CarbonImmutable::now()->toIso8601String(),
        'payload' => ['day' => '2026-11-09', 'text' => 'hello'],
        ...$over,
    ];
}

function ingest(Tests\TestCase $t, string $token, array $events, string $site = 'mats'): Illuminate\Testing\TestResponse
{
    return $t->withToken($token)->postJson("/api/v1/sites/{$site}/events", ['batch_id' => (string) Str::uuid7(), 'events' => $events]);
}

it('ingests a batch in order with accepted, duplicate and rejected results', function (): void {
    $first = note(['provisional_ref' => 'tmp-1']);
    $bad = note();
    unset($bad['occurred_at']);
    $r = ingest($this, $this->token, [$first, $first, note(['type' => 'notes.unknown_thing']), $bad])->assertOk();

    $seq = $r->json('results.0.seq');
    expect($r->json('results'))->toHaveCount(4)
        ->and($r->json('results.0.status'))->toBe('accepted')
        ->and($r->json('results.0.assigned'))->toBe(['tmp-1' => 'NT-00001'])
        ->and($r->json('results.1'))->toBe(['id' => $first['id'], 'status' => 'duplicate', 'seq' => $seq, 'assigned' => ['tmp-1' => 'NT-00001']])
        ->and($r->json('results.2.status'))->toBe('rejected')
        ->and($r->json('results.2.code'))->toBe('unknown_type')
        ->and($r->json('results.3.code'))->toBe('invalid_payload')
        ->and($r->json('snapshot_seq'))->toBe((int) DB::table('events')->max('seq'));
});

it('rejects an envelope addressed to another site', function (): void {
    ingest($this, $this->token, [note(['site_id' => $this->bags->id])])->assertOk()
        ->assertJsonPath('results.0.status', 'rejected')->assertJsonPath('results.0.code', 'forbidden');
});

it('rejects an occurred_at more than a day ahead', function (): void {
    ingest($this, $this->token, [note(['occurred_at' => CarbonImmutable::now()->addHours(48)->toIso8601String()])])->assertOk()
        ->assertJsonPath('results.0.code', 'invalid_payload');
});

it('refuses a batch over the maximum', function (): void {
    config(['tanaad.ingest_batch_max' => 2]);
    ingest($this, $this->token, [note(), note(), note()])->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')->assertJsonPath('code', 'invalid_payload');
});

it('forbids a cashier of another site', function (): void {
    $bags = User::query()->where('code', 'u_cash_bags')->firstOrFail()->createToken('office', ['user'])->plainTextToken;
    ingest($this, $bags, [note()])->assertForbidden();
});

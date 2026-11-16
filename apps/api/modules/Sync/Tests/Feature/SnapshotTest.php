<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
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
    $mats = Site::query()->where('code', 'mats')->firstOrFail();
    app(NumberSeries::class)->define($mats->id, 'note', 'NT');
    $this->token = User::query()->where('code', 'u_cash_mats')->firstOrFail()->createToken('office', ['user'])->plainTextToken;
    $events = array_map(fn (int $i): array => [
        'id' => (string) Str::uuid7(),
        'type' => 'notes.note_added',
        'occurred_at' => CarbonImmutable::now()->toIso8601String(),
        'payload' => ['day' => '2026-11-09', 'text' => "note {$i}"],
    ], [1, 2, 3]);
    $r = $this->withToken($this->token)->postJson('/api/v1/sites/mats/events', ['batch_id' => (string) Str::uuid7(), 'events' => $events])->assertOk();
    $this->seqs = array_column($r->json('results'), 'seq');
});

it('returns a full load in camelCase', function (): void {
    $r = $this->withToken($this->token)->getJson('/api/v1/sites/mats/snapshots/test_notes?since=0')->assertOk();
    expect($r->json('data'))->toHaveCount(3)
        ->and($r->json('data.0'))->toHaveKeys(['id', 'siteId', 'number', 'seq'])
        ->and($r->json('data.0.seq'))->toBe($this->seqs[0])
        ->and($r->json('has_more'))->toBeFalse()
        ->and($r->json('projection'))->toBe('test_notes');
});

it('returns only rows after since', function (): void {
    $this->withToken($this->token)->getJson("/api/v1/sites/mats/snapshots/test_notes?since={$this->seqs[1]}")
        ->assertOk()->assertJsonCount(1, 'data');
});

it('pages with a seq cursor', function (): void {
    $this->withToken($this->token)->getJson('/api/v1/sites/mats/snapshots/test_notes?since=0&limit=2')->assertOk()
        ->assertJsonCount(2, 'data')->assertJsonPath('has_more', true)->assertJsonPath('snapshot_seq', $this->seqs[1]);
});

it('answers an unknown projection with not_found', function (): void {
    $this->withToken($this->token)->getJson('/api/v1/sites/mats/snapshots/nope')->assertNotFound()->assertJsonPath('code', 'not_found');
});

it('answers many projections in one batch with per-table cursors and inline refusals', function (): void {
    app(Snapshots::class)->register('test_notes_mgr', 'test_notes', roles: ['site_manager']);
    $r = $this->withToken($this->token)
        ->getJson("/api/v1/sites/mats/snapshots?tables=test_notes,test_notes_mgr,nope,test_notes_mgr&cursors=test_notes:{$this->seqs[0]}")
        ->assertOk();
    expect(array_keys($r->json('results')))->toBe(['test_notes', 'test_notes_mgr', 'nope'])
        ->and($r->json('results.test_notes.status'))->toBe(200)
        ->and($r->json('results.test_notes.data'))->toHaveCount(2)
        ->and($r->json('results.test_notes.has_more'))->toBeFalse()
        ->and($r->json('results.test_notes_mgr'))->toMatchArray(['status' => 403, 'code' => 'forbidden'])
        ->and($r->json('results.nope'))->toMatchArray(['status' => 404, 'code' => 'not_found'])
        ->and($r->json('snapshot_seq'))->toBe($this->seqs[2]);
    // Same rows as the single endpoint.
    $single = $this->withToken($this->token)->getJson("/api/v1/sites/mats/snapshots/test_notes?since={$this->seqs[0]}")->json('data');
    expect($r->json('results.test_notes.data'))->toBe($single);
    $this->withToken($this->token)->getJson('/api/v1/sites/mats/snapshots')->assertStatus(422);
    $this->withToken($this->token)->getJson('/api/v1/sites/bags/snapshots?tables=test_notes')->assertForbidden();
});

it('serialises snapshot datetimes as UTC with Z', function (): void {
    $row = $this->withToken($this->token)->getJson('/api/v1/sites/mats/snapshots/test_notes?since=0')->json('data.0');
    foreach ($row as $key => $value) {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:/', $value) === 1) {
            expect($value)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{3})?Z$/');
        }
    }
});

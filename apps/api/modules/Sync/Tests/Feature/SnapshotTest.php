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
    $this->seed([SitesSeeder::class, DemoUsersSeeder::class]);
    NotesFixture::install();
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

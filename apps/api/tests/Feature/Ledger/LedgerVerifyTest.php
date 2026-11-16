<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\LedgerChecksum;
use Tanaad\Shared\Ledger\NumberSeries;
use Tests\Fixtures\Notes\AddNote;
use Tests\Fixtures\Notes\NotesFixture;

beforeEach(function (): void {
    NotesFixture::install();
    $this->site = (string) Str::uuid7();
    app(NumberSeries::class)->define($this->site, 'note', 'NT');
    $this->add = function (string $text): string {
        $r = app(CommandBus::class)->dispatch(new AddNote(new CommandContext($this->site, (string) Str::uuid7(), (string) Str::uuid7(), CarbonImmutable::parse('2026-11-09 10:00:00')), '2026-11-09', $text));

        return $r->primary()->id();
    };
});

/** Insert a raw row the way an outsider with INSERT rights could: bypasses EventStore. */
function rawEvent(array $overrides = []): void
{
    DB::table('events')->insert(array_merge([
        'id' => (string) Str::uuid7(), 'site_id' => null, 'type' => 'notes.note_added', 'version' => 1, 'actor_id' => null, 'device_id' => null,
        'occurred_at' => '2026-11-09 10:00:00', 'received_at' => now(), 'payload' => '{"x":1}', 'corrects_event_id' => null, 'reason' => null,
        'batch_id' => null, 'checksum' => null,
    ], $overrides));
}

it('passes on a clean ledger, empty or not', function (): void {
    $this->artisan('ledger:verify')->assertSuccessful();
    ($this->add)('one');
    ($this->add)('two');
    $this->artisan('ledger:verify', ['--replay' => true])->expectsOutputToContain('Scanned 2 event(s).')->assertSuccessful();
});

it('checksums events at append and recomputes them from the stored row', function (): void {
    $id = ($this->add)('three');
    $row = DB::table('events')->where('id', $id)->first();
    expect($row->checksum)->toHaveLength(64)->and(LedgerChecksum::forRow($row))->toBe($row->checksum);
});

it('fails with a non-zero exit and a critical log when a row was tampered with', function (): void {
    ($this->add)('honest');
    $good = DB::table('events')->orderByDesc('seq')->first();
    // tampered copy: new id, altered payload, the original checksum
    rawEvent(['id' => (string) Str::uuid7(), 'type' => $good->type, 'site_id' => $good->site_id, 'payload' => '{"text":"forged"}', 'checksum' => $good->checksum]);

    Log::spy();
    $this->artisan('ledger:verify')->expectsOutputToContain('fail their checksum')->assertFailed();
    Log::shouldHaveReceived('critical')->once();
});

it('fails when an unsigned row is inserted after signed ones', function (): void {
    ($this->add)('signed');
    rawEvent();
    $this->artisan('ledger:verify')->expectsOutputToContain('without a checksum appear after')->assertFailed();
});

it('reports seq gaps as a warning, and as a failure with --strict-gaps', function (): void {
    ($this->add)('a');
    $row = [
        'id' => (string) Str::uuid7(), 'site_id' => null, 'type' => 'notes.note_added', 'version' => 1, 'actor_id' => null, 'device_id' => null,
        'occurred_at' => '2026-11-09 10:00:00', 'received_at' => now(), 'payload' => '{"x":1}', 'corrects_event_id' => null, 'reason' => null,
    ];
    DB::table('events')->insert([...$row, 'seq' => (int) DB::table('events')->max('seq') + 10, 'batch_id' => null, 'checksum' => LedgerChecksum::forRow((object) $row)]);
    $this->artisan('ledger:verify')->expectsOutputToContain('seq gap')->assertSuccessful();
    $this->artisan('ledger:verify', ['--strict-gaps' => true])->assertFailed();
});

it('flags received_at running backwards', function (): void {
    ($this->add)('now');
    $signedLater = ($this->add)('later');
    rawEvent(['received_at' => now()->subHours(3), 'checksum' => null]);
    $this->artisan('ledger:verify')->expectsOutputToContain('runs backwards')->assertFailed();
    expect($signedLater)->toBeString();
});

it('detects a projection that no longer equals a replay', function (): void {
    ($this->add)('one');
    ($this->add)('two');
    DB::table('test_notes')->limit(1)->update(['text' => 'edited behind the ledger']);
    $this->artisan('ledger:verify', ['--replay' => true])->expectsOutputToContain('test_notes')->assertFailed();
    // the replay ran in a rolled-back transaction: the drifted row is still there for inspection
    expect(DB::table('test_notes')->where('text', 'edited behind the ledger')->exists())->toBeTrue();
});

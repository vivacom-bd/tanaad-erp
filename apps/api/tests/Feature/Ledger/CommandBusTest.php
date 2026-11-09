<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tanaad\Shared\Ledger\CommandBus;
use Tanaad\Shared\Ledger\CommandContext;
use Tanaad\Shared\Ledger\DayLocks;
use Tanaad\Shared\Ledger\NumberSeries;
use Tanaad\Shared\Ledger\Rejection;
use Tanaad\Shared\Ledger\RejectionCode;
use Tanaad\Shared\Ledger\Replay;
use Tanaad\Shared\Ledger\RunReactor;
use Tests\Fixtures\Notes\AddNote;
use Tests\Fixtures\Notes\NoteProjector;
use Tests\Fixtures\Notes\NoteReactor;
use Tests\Fixtures\Notes\NotesFixture;

beforeEach(function (): void {
    NotesFixture::install();
    $this->site = (string) Str::uuid7();
    app(NumberSeries::class)->define($this->site, 'note', 'NT');
    $this->ctx = fn (?string $eventId = null, ?string $ref = null) => new CommandContext(
        $this->site, (string) Str::uuid7(), (string) Str::uuid7(), CarbonImmutable::parse('2026-11-09 10:00:00'), $eventId, $ref,
    );
});

it('stores the event, projects it and assigns a number', function (): void {
    $result = app(CommandBus::class)->dispatch(new AddNote(($this->ctx)(null, 'tmp-1'), '2026-11-09', 'first'));

    expect($result->status)->toBe('accepted')
        ->and($result->events)->toHaveCount(1)
        ->and($result->primary()->type())->toBe('notes.note_added')
        ->and($result->primary()->seq)->toBeGreaterThan(0)
        ->and($result->assigned)->toBe(['tmp-1' => 'NT-00001']);

    $row = DB::table('test_notes')->first();
    expect($row->number)->toBe('NT-00001')->and($row->text)->toBe('first');
    expect(DB::table('projection_cursors')->where('projector', NoteProjector::class)->value('last_seq'))->toBe($result->primary()->seq);
});

it('reports a duplicate when the same client event id is sent twice', function (): void {
    $id = (string) Str::uuid7();
    $bus = app(CommandBus::class);
    $first = $bus->dispatch(new AddNote(($this->ctx)($id), '2026-11-09', 'once'));
    $second = $bus->dispatch(new AddNote(($this->ctx)($id), '2026-11-09', 'once again'));

    expect($first->primary()->id())->toBe($id)
        ->and($second->isDuplicate())->toBeTrue()
        ->and($second->primary()->seq)->toBe($first->primary()->seq)
        ->and(DB::table('events')->count())->toBe(1);
});

it('rejects a write into a locked day and leaves nothing behind', function (): void {
    app(DayLocks::class)->lock($this->site, 'notes', '2026-11-09', (string) Str::uuid7());

    try {
        app(CommandBus::class)->dispatch(new AddNote(($this->ctx)(), '2026-11-09', 'late'));
        $this->fail('expected a rejection');
    } catch (Rejection $e) {
        expect($e->rejectionCode)->toBe(RejectionCode::DayLocked);
    }

    expect(DB::table('events')->count())->toBe(0)
        ->and(DB::table('number_series')->value('next'))->toBe(1);
});

it('runs policies in the same transaction and queues reactors after commit', function (): void {
    Queue::fake();
    $result = app(CommandBus::class)->dispatch(new AddNote(($this->ctx)(), '2026-11-09', 'urgent one', urgent: true));

    expect(DB::table('events')->count())->toBe(2)
        ->and(DB::table('events')->where('type', 'notes.note_flagged')->exists())->toBeTrue()
        ->and((bool) DB::table('test_notes')->value('flagged'))->toBeTrue()
        ->and($result->events)->toHaveCount(1);

    Queue::assertPushed(RunReactor::class, fn (RunReactor $j) => $j->reactor === NoteReactor::class && $j->eventId === $result->primary()->id());
});

it('runs a reactor exactly once', function (): void {
    $result = app(CommandBus::class)->dispatch(new AddNote(($this->ctx)(), '2026-11-09', 'reacted'));
    $job = new RunReactor(NoteReactor::class, $result->primary()->id());
    $job->handle(DB::connection(), app(Tanaad\Shared\Ledger\EventStore::class));
    $job->handle(DB::connection(), app(Tanaad\Shared\Ledger\EventStore::class));

    expect(NoteReactor::$seen)->toHaveCount(1)
        ->and(DB::table('reactor_runs')->count())->toBe(1);
});

it('rebuilds the projection to exactly the same rows', function (): void {
    $bus = app(CommandBus::class);
    $bus->dispatch(new AddNote(($this->ctx)(), '2026-11-09', 'a'));
    $bus->dispatch(new AddNote(($this->ctx)(), '2026-11-09', 'b', urgent: true));
    $bus->dispatch(new AddNote(($this->ctx)(), '2026-11-10', 'c'));
    $before = DB::table('test_notes')->orderBy('seq')->get()->map(fn ($r) => (array) $r)->all();

    DB::table('test_notes')->update(['text' => 'corrupted']);
    $replayed = app(Replay::class)->rebuild();

    $after = DB::table('test_notes')->orderBy('seq')->get()->map(fn ($r) => (array) $r)->all();
    expect($replayed)->toBe(4)->and($after)->toBe($before)
        ->and(DB::table('projection_cursors')->value('last_seq'))->toBe((int) DB::table('events')->max('seq'));
});

it('never lets the ledger be updated or deleted', function (): void {
    app(CommandBus::class)->dispatch(new AddNote(($this->ctx)(), '2026-11-09', 'permanent'));

    $attempt = function (string $sql): string {
        try {
            DB::transaction(fn () => DB::statement($sql));

            return 'allowed';
        } catch (Illuminate\Database\QueryException $e) {
            return $e->getMessage();
        }
    };

    expect($attempt("update events set reason = 'x'"))->toContain('append-only')
        ->and($attempt('delete from events'))->toContain('append-only')
        ->and(DB::table('events')->count())->toBe(1);
});

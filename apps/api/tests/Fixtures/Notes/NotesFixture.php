<?php

declare(strict_types=1);

namespace Tests\Fixtures\Notes;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tanaad\Shared\Ledger\Registry;

/**
 * Registers the Notes test module and creates its projection table.
 */
final class NotesFixture
{
    /** Create the projection table. MySQL commits implicitly on DDL, so close the test transaction around it and reopen. */
    public static function createTable(): void
    {
        if (! Schema::hasTable('test_notes')) {
            $level = DB::transactionLevel();
            for ($i = 0; $i < $level; $i++) {
                DB::rollBack();
            }
            Schema::create('test_notes', function (Blueprint $t): void {
                $t->uuid('id')->primary();
                $t->uuid('site_id');
                $t->string('number');
                $t->date('day');
                $t->text('text');
                $t->boolean('flagged');
                $t->unsignedBigInteger('seq');
            });
            for ($i = 0; $i < $level; $i++) {
                DB::beginTransaction();
            }
        }
    }

    public static function install(): void
    {
        self::createTable();
        NoteReactor::$seen = [];
        app(Registry::class)
            ->command(AddNote::class, AddNoteHandler::class)
            ->command(FlagNote::class, FlagNoteHandler::class)
            ->eventType('notes.note_added', AddNoteFactory::class)
            ->projectors(NoteProjector::class)
            ->policies(UrgentNotePolicy::class)
            ->reactors(NoteReactor::class);
    }
}

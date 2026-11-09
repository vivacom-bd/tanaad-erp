<?php

declare(strict_types=1);

namespace Tests\Fixtures\Notes;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tanaad\Shared\Ledger\Registry;

/**
 * Registers the Notes test module and creates its projection table.
 */
final class NotesFixture
{
    public static function install(): void
    {
        if (! Schema::hasTable('test_notes')) {
            Schema::create('test_notes', function (Blueprint $t): void {
                $t->uuid('id')->primary();
                $t->uuid('site_id');
                $t->string('number');
                $t->date('day');
                $t->text('text');
                $t->boolean('flagged');
                $t->unsignedBigInteger('seq');
            });
        }
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

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table): void {
            $table->bigIncrements('seq');                       // global order; the cursor sync reads by
            $table->uuid('id')->unique();
            $table->uuid('site_id')->nullable()->index();
            $table->string('type', 120)->index();
            $table->unsignedSmallInteger('version')->default(1);
            $table->uuid('actor_id')->nullable();
            $table->uuid('device_id')->nullable();
            $table->dateTime('occurred_at', 6);
            $table->dateTime('received_at', 6);
            $table->json('payload');
            $table->uuid('corrects_event_id')->nullable();
            $table->text('reason')->nullable();
            $table->uuid('batch_id')->nullable()->index();

            $table->index(['site_id', 'seq']);
            $table->index(['site_id', 'occurred_at']);
        });

        Schema::table('events', function (Blueprint $table): void {
            $table->foreign('corrects_event_id')->references('id')->on('events');
        });

        // Append-only at the database level: no row is ever updated or deleted.
        DB::unprepared("CREATE TRIGGER events_no_update BEFORE UPDATE ON events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'events is append-only (UPDATE)'");
        DB::unprepared("CREATE TRIGGER events_no_delete BEFORE DELETE ON events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'events is append-only (DELETE)'");

        Schema::create('day_locks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('site_id');
            $table->string('ledger', 32);
            $table->date('day');
            $table->uuid('locked_by_event');
            $table->uuid('reopened_by_event')->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();

            $table->unique(['site_id', 'ledger', 'day']);
        });

        Schema::create('number_series', function (Blueprint $table): void {
            $table->uuid('site_id');
            $table->string('kind', 32);
            $table->string('prefix', 16);
            $table->unsignedTinyInteger('pad')->default(5);
            $table->unsignedBigInteger('next')->default(1);

            $table->primary(['site_id', 'kind']);
        });

        Schema::create('projection_cursors', function (Blueprint $table): void {
            $table->string('projector', 191)->primary();
            $table->unsignedBigInteger('last_seq')->default(0);
            $table->dateTime('updated_at', 6)->nullable();
        });

        Schema::create('reactor_runs', function (Blueprint $table): void {
            $table->string('reactor', 191);
            $table->uuid('event_id');
            $table->dateTime('ran_at', 6);
            $table->text('error')->nullable();

            $table->primary(['reactor', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reactor_runs');
        Schema::dropIfExists('projection_cursors');
        Schema::dropIfExists('number_series');
        Schema::dropIfExists('day_locks');
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('events');
        Schema::enableForeignKeyConstraints();
    }
};

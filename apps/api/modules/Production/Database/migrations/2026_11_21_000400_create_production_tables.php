<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tail = function (Blueprint $table): void {
            $table->uuid('event_id');
            $table->uuid('recorded_by')->nullable();
            $table->dateTime('at', 6);
            $table->unsignedBigInteger('seq')->default(0)->index();
            $table->dateTime('deleted_at', 6)->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
        };

        // One row per (shift, machine, stage); a re-log replaces the row and keeps its id.
        Schema::create('production_entries', function (Blueprint $table) use ($tail): void {
            $table->uuid('id')->primary();
            $table->uuid('site_id')->index();
            $table->string('shift_id', 80);
            $table->date('day');
            $table->string('shift_kind', 16);
            $table->uuid('machine_id')->index();
            $table->string('stage_code', 32);
            $table->uuid('product_id')->nullable();
            $table->uuid('operator_id')->nullable();
            $table->unsignedInteger('good_units')->default(0);
            $table->unsignedInteger('reject_units')->default(0);
            $table->decimal('input_kg', 12, 3)->default(0);
            $table->decimal('output_kg', 12, 3)->default(0);
            $table->unsignedInteger('downtime_min')->default(0);
            $table->string('downtime_reason', 255)->nullable();
            $table->json('label_codes')->nullable();
            $table->decimal('benchmark', 12, 3)->nullable();
            $table->boolean('below_benchmark')->default(false);
            $tail($table);
            $table->unique(['shift_id', 'machine_id', 'stage_code']);
            $table->index(['site_id', 'day']);
        });

        Schema::create('production_batches', function (Blueprint $table) use ($tail): void {
            $table->uuid('id')->primary();
            $table->uuid('site_id')->index();
            $table->date('day');
            $table->string('shift_id', 80)->nullable();
            $table->uuid('machine_id')->nullable();
            $table->uuid('product_id')->nullable();
            $table->json('lines');                              // [{material, kg}]
            $table->decimal('input_kg', 12, 3);
            $table->decimal('output_kg', 12, 3);
            $table->string('label_code', 64)->nullable();
            $table->string('note', 1000)->nullable();
            $tail($table);
            $table->index(['site_id', 'day']);
        });

        Schema::create('production_shifts_closed', function (Blueprint $table) use ($tail): void {
            $table->string('id', 80)->primary();                // the shift id
            $table->uuid('site_id')->index();
            $table->date('day');
            $table->string('shift_kind', 16);
            $table->json('unlogged_machine_ids');
            $tail($table);
        });

        Schema::create('waste_weighings', function (Blueprint $table) use ($tail): void {
            $table->uuid('id')->primary();
            $table->uuid('site_id')->index();
            $table->date('day');
            $table->string('stage_code', 32);
            $table->string('waste_kind', 64);                   // ref_items code (list waste_source), user-managed
            $table->decimal('kg', 12, 3);
            $table->string('reason', 500)->nullable();
            $table->uuid('machine_id')->nullable();
            $tail($table);
            $table->index(['site_id', 'day']);
        });
    }

    public function down(): void
    {
        foreach (['waste_weighings', 'production_shifts_closed', 'production_batches', 'production_entries'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};

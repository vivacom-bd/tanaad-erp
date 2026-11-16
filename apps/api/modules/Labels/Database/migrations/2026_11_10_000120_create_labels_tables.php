<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('labels', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('site_id')->index();
            $table->string('code', 64);
            $table->string('kind', 16);
            $table->uuid('product_id')->nullable()->index();
            $table->string('stage_code', 32);
            $table->uuid('machine_id')->nullable();
            $table->uuid('operator_id')->nullable();
            $table->string('shift_kind', 32)->nullable();
            $table->date('day');
            $table->decimal('qty_units', 14, 3)->default(0);
            $table->decimal('qty_kg', 14, 3)->default(0);
            $table->string('status', 16);                       // created | in_stock | consumed | dispatched | scrapped
            $table->uuid('zone_id')->nullable();
            $table->json('parent_ids');
            $table->string('colour', 64)->nullable();
            $table->string('grade', 64)->nullable();
            $table->string('consumed_by_stage', 32)->nullable();
            $table->string('consumed_ref', 120)->nullable();
            $table->uuid('event_id');
            $table->uuid('created_by')->nullable();
            $table->dateTime('at', 6);
            $table->unsignedBigInteger('seq')->default(0)->index();
            $table->dateTime('deleted_at', 6)->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
            $table->index(['site_id', 'status', 'kind']);
            $table->index(['site_id', 'day']);
            $table->unique(['site_id', 'code']);                // codes are per-site series
            $table->index('code');
        });

        Schema::create('label_history', function (Blueprint $table): void {
            $table->id();
            $table->uuid('label_id');
            $table->uuid('site_id');
            $table->string('type', 32);                         // created | consumed | moved | scanned | dispatched | scrapped ...
            $table->json('data')->nullable();
            $table->uuid('event_id');
            $table->uuid('by')->nullable();
            $table->dateTime('at', 6);
            $table->unsignedBigInteger('seq')->default(0);
            $table->unique(['label_id', 'event_id']);
            $table->index(['label_id', 'seq']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('label_history');
        Schema::dropIfExists('labels');
    }
};

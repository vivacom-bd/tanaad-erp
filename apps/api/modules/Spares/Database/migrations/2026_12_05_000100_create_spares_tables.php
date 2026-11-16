<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('site_id');
            $table->string('code', 32);
            $table->unique(['site_id', 'code']);
            $table->string('name');
            $table->string('unit', 32);
            $table->decimal('min_level', 14, 3);
            $table->decimal('unit_cost', 18, 4);          // current average cost
            $table->decimal('on_hand', 14, 3)->default(0);
            $table->json('machine_kinds')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('seq')->default(0)->index();
            $table->dateTime('deleted_at', 6)->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
        });

        Schema::create('part_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('site_id')->index();
            $table->uuid('part_id')->index();
            $table->decimal('qty', 14, 3);
            $table->decimal('unit_cost', 18, 4);
            $table->string('supplier', 160)->nullable();
            $table->uuid('event_id');
            $table->uuid('received_by')->nullable();
            $table->dateTime('at', 6);
            $table->unsignedBigInteger('seq')->default(0)->index();
            $table->dateTime('deleted_at', 6)->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
        });

        Schema::create('part_issues', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('site_id')->index();
            $table->uuid('part_id')->index();
            $table->uuid('machine_id')->index();
            $table->uuid('mechanic_id')->nullable();
            $table->uuid('breakdown_id')->nullable()->index();
            $table->decimal('qty', 14, 3);
            $table->decimal('unit_cost', 18, 4);
            $table->decimal('cost', 18, 2);
            $table->string('reason', 255)->nullable();
            $table->uuid('event_id');
            $table->uuid('issued_by')->nullable();
            $table->dateTime('at', 6)->index();
            $table->unsignedBigInteger('seq')->default(0)->index();
            $table->dateTime('deleted_at', 6)->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
        });

        Schema::create('breakdowns', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('site_id')->index();
            $table->uuid('machine_id')->index();
            $table->string('cause', 500);
            $table->dateTime('started_at', 6);
            $table->dateTime('resolved_at', 6)->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->decimal('spares_cost', 18, 2)->default(0);
            $table->uuid('reported_by')->nullable();
            $table->uuid('resolved_by')->nullable();
            $table->uuid('event_id');
            $table->unsignedBigInteger('seq')->default(0)->index();
            $table->dateTime('deleted_at', 6)->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
            $table->index(['site_id', 'started_at']);
        });
    }

    public function down(): void
    {
        foreach (['breakdowns', 'part_issues', 'part_receipts', 'parts'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};

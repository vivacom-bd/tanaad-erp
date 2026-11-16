<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('site_id')->index();
            $table->string('reference', 64);
            $table->unique(['site_id', 'reference']);
            $table->string('supplier', 160);
            $table->string('origin_country', 80)->nullable();
            $table->string('material', 160);
            $table->decimal('qty_kg', 14, 3);
            $table->decimal('unit_price', 16, 4);
            $table->char('currency', 3);
            $table->decimal('fx_reference', 16, 6)->nullable();
            $table->json('costs');
            $table->string('status', 16);
            $table->date('ordered_on');
            $table->date('expected_on')->nullable();
            $table->string('note', 1000)->nullable();
            $table->date('received_on')->nullable();
            $table->decimal('received_kg', 14, 3)->nullable();
            $table->boolean('variance')->default(false);
            $table->decimal('goods_ugx', 18, 2)->nullable();
            $table->decimal('extras_ugx', 18, 2)->nullable();
            $table->decimal('total_ugx', 18, 2)->nullable();
            $table->decimal('per_kg_ugx', 16, 2)->nullable();
            $table->decimal('uplift_pct', 10, 2)->nullable();
            $table->uuid('event_id');
            $table->uuid('recorded_by')->nullable();
            $table->uuid('received_by')->nullable();
            $table->dateTime('at', 6);
            $table->unsignedBigInteger('seq')->default(0)->index();
            $table->dateTime('deleted_at', 6)->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
            $table->index(['site_id', 'ordered_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};

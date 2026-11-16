<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collector_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('site_id')->index();
            $table->date('day');
            $table->string('collector', 160)->index();
            $table->decimal('gross_kg', 14, 3);
            $table->decimal('tare_kg', 14, 3);
            $table->decimal('deduction_pct', 7, 3);
            $table->uuid('deduction_preset_id')->nullable();
            $table->decimal('net_kg', 14, 3);
            $table->decimal('price_per_kg', 14, 2);
            $table->decimal('amount', 16, 2);
            $table->char('currency', 3);
            $table->boolean('has_scale_photo')->default(false);
            $table->string('scale_photo_hash', 128)->nullable();
            $table->string('voucher_no', 64);
            $table->unique(['site_id', 'voucher_no']);
            $table->string('provisional_no', 64)->nullable();
            $table->boolean('paid')->default(false);
            $table->dateTime('paid_at', 6)->nullable();
            $table->date('paid_day')->nullable();
            $table->decimal('paid_amount', 16, 2)->nullable();
            $table->uuid('payment_id')->nullable()->unique();
            $table->uuid('paid_by')->nullable();
            $table->uuid('event_id');
            $table->uuid('recorded_by')->nullable();
            $table->dateTime('at', 6);
            $table->unsignedBigInteger('seq')->default(0)->index();
            $table->dateTime('deleted_at', 6)->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
            $table->index(['site_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collector_deliveries');
    }
};

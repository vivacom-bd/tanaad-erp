<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_days', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('site_id')->index();
            $table->date('day');
            $table->unique(['site_id', 'day']);
            $table->char('currency', 3);
            $table->decimal('opening', 16, 2)->default(0);
            $table->string('status', 16);                       // open | closed | reopened
            $table->decimal('expected_closing', 16, 2)->nullable();
            $table->decimal('counted_closing', 16, 2)->nullable();
            $table->decimal('variance', 16, 2)->nullable();
            $table->json('denominations')->nullable();
            $table->uuid('signed_by')->nullable();
            $table->dateTime('closed_at', 6)->nullable();
            $table->string('reopen_reason', 500)->nullable();
            $table->unsignedBigInteger('seq')->default(0)->index();
            $table->dateTime('deleted_at', 6)->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
        });

        Schema::create('cash_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('site_id')->index();
            $table->uuid('cash_day_id')->index();
            $table->date('day');
            $table->string('direction', 3);                     // in | out
            $table->string('category', 64)->index();            // ref_items code, user-managed
            $table->string('subcategory', 120)->nullable();
            $table->decimal('amount', 16, 2);
            $table->char('currency', 3);
            $table->decimal('fx_reference', 16, 4)->nullable();
            $table->uuid('machine_id')->nullable();
            $table->uuid('product_id')->nullable();
            $table->string('payee')->nullable();
            $table->string('note', 1000)->nullable();
            $table->boolean('has_receipt_photo')->default(false);
            $table->uuid('payout_id')->nullable()->unique();
            $table->uuid('corrects_entry_id')->nullable()->index();
            $table->uuid('superseded_by')->nullable();
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
        Schema::dropIfExists('cash_entries');
        Schema::dropIfExists('cash_days');
    }
};

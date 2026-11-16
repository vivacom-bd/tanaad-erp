<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $common = function (Blueprint $table): void {
            $table->uuid('event_id');
            $table->unsignedBigInteger('seq')->default(0)->index();
            $table->dateTime('deleted_at', 6)->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
        };

        // Group-wide master data: site_id is always null (the snapshot reads it from every site).
        Schema::create('clients', function (Blueprint $table) use ($common): void {
            $table->uuid('id')->primary();
            $table->uuid('site_id')->nullable()->index();
            $table->uuid('registered_site_id')->nullable();
            $table->string('name', 160);
            $table->string('country', 80);
            $table->string('channel', 16);
            $table->string('whatsapp', 40)->default('');
            $table->string('contact', 160)->default('');
            $table->char('currency_pref', 3);
            $table->decimal('credit_limit', 16, 2)->default(0);
            $table->integer('credit_days')->default(0);
            $table->boolean('active')->default(true);
            $common($table);
        });

        Schema::create('orders', function (Blueprint $table) use ($common): void {
            $table->uuid('id')->primary();
            $table->uuid('site_id')->index();
            $table->string('number', 64);
            $table->unique(['site_id', 'number']);
            $table->uuid('client_id')->index();
            $table->string('client_name', 160);
            $table->string('channel', 16);
            $table->string('status', 24)->index();
            $table->char('currency', 3);
            $table->decimal('fx_reference', 16, 6)->nullable();
            $table->decimal('total', 16, 2);
            $table->json('lines');
            $table->boolean('over_limit')->default(false);
            $table->string('notes', 1000)->nullable();
            $table->date('day');
            $table->dateTime('cash_confirmed_at', 6)->nullable();
            $table->decimal('cash_amount', 16, 2)->nullable();
            $table->uuid('cash_entry_id')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->dateTime('approved_at', 6)->nullable();
            $table->string('hold_reason', 1000)->nullable();
            $table->uuid('invoice_id')->nullable();
            $table->boolean('fully_paid')->default(false);
            $table->uuid('created_by')->nullable();
            $table->dateTime('at', 6);
            $common($table);
        });

        Schema::create('invoices', function (Blueprint $table) use ($common): void {
            $table->uuid('id')->primary();
            $table->uuid('site_id')->index();
            $table->uuid('order_id')->unique();
            $table->string('order_number', 64);
            $table->uuid('client_id')->index();
            $table->string('number', 64);
            $table->unique(['site_id', 'number']);
            $table->decimal('total', 16, 2);
            $table->char('currency', 3);
            $table->decimal('paid_amount', 16, 2)->default(0);
            $table->decimal('balance', 16, 2)->default(0);  // total − paid_amount, kept by the projector
            $table->date('issued_day');
            $table->dateTime('issued_at', 6);
            $table->string('efris_fdn', 64)->nullable();
            $table->string('efris_verification_code', 64)->nullable();
            $table->uuid('issued_by')->nullable();
            $common($table);
        });

        Schema::create('payments', function (Blueprint $table) use ($common): void {
            $table->uuid('id')->primary();
            $table->uuid('site_id')->index();
            $table->uuid('client_id')->index();
            $table->uuid('invoice_id')->index();
            $table->uuid('order_id');
            $table->decimal('amount', 16, 2);
            $table->char('currency', 3);
            $table->string('method', 80);
            $table->boolean('is_cash')->default(false);
            $table->string('reference', 120)->default('');
            $table->date('day');
            $table->dateTime('at', 6);
            $table->uuid('recorded_by')->nullable();
            $common($table);
        });
    }

    public function down(): void
    {
        foreach (['payments', 'invoices', 'orders', 'clients'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};

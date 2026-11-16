<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // On hand per site, product and zone. Written only by StockProjector.
        Schema::create('stock_on_hand', function (Blueprint $table): void {
            $table->string('id', 120)->primary();               // site|product|zone (zone "none" when unzoned)
            $table->uuid('site_id')->index();
            $table->uuid('product_id');
            $table->uuid('zone_id')->nullable();
            $table->integer('bundles')->default(0);
            $table->decimal('pieces', 14, 3)->default(0);
            $table->decimal('kg', 14, 3)->default(0);
            $table->unsignedBigInteger('seq')->default(0)->index();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
            $table->index(['site_id', 'product_id']);
        });

        // Stock's own copy of where each label sits and whether it counts, taken from label events;
        // the labels table stays Labels' own.
        Schema::create('stock_label_positions', function (Blueprint $table): void {
            $table->uuid('label_id')->primary();
            $table->uuid('site_id');
            $table->string('code', 64);
            $table->uuid('product_id')->nullable();
            $table->uuid('zone_id')->nullable();
            $table->decimal('qty_units', 14, 3)->default(0);
            $table->decimal('qty_kg', 14, 3)->default(0);
            $table->boolean('in_stock');
            $table->unsignedBigInteger('seq')->default(0);
            $table->unique(['site_id', 'code']);
        });

        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();                      // the event id: one movement per event
            $table->uuid('site_id');
            $table->string('kind', 24);                         // production_in | consumed | dispatch_out | receive | transfer | label_move | adjust | scrap | transfer_out | transfer_in
            $table->uuid('label_id')->nullable();
            $table->string('label_code', 64)->nullable();
            $table->uuid('product_id')->nullable();
            $table->uuid('from_zone_id')->nullable();
            $table->uuid('to_zone_id')->nullable();
            $table->uuid('from_site_id')->nullable();
            $table->uuid('to_site_id')->nullable();
            $table->uuid('transfer_id')->nullable();
            $table->uuid('count_id')->nullable();
            $table->integer('bundles')->default(0);             // signed: + into the site, - out of it
            $table->decimal('qty_units', 14, 3)->default(0);
            $table->decimal('qty_kg', 14, 3)->default(0);
            $table->string('reason', 500)->nullable();
            $table->string('cause', 120)->nullable();
            $table->uuid('by')->nullable();
            $table->dateTime('at', 6);
            $table->unsignedBigInteger('seq')->default(0)->index();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
            $table->index(['site_id', 'seq']);
        });

        Schema::create('stock_transfers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('site_id')->index();                   // the sending site
            $table->uuid('to_site_id')->index();
            $table->uuid('product_id');                         // the sender's product
            $table->uuid('received_product_id')->nullable();    // the receiving site's product
            $table->uuid('from_zone_id')->nullable();
            $table->uuid('to_zone_id')->nullable();
            $table->integer('bundles')->default(0);
            $table->decimal('qty_units', 14, 3)->default(0);
            $table->decimal('qty_kg', 14, 3)->default(0);
            $table->string('status', 16);                       // sent | received
            $table->integer('received_bundles')->nullable();
            $table->decimal('received_units', 14, 3)->nullable();
            $table->decimal('received_kg', 14, 3)->nullable();
            $table->integer('discrepancy_bundles')->nullable();
            $table->decimal('discrepancy_units', 14, 3)->nullable();
            $table->decimal('discrepancy_kg', 14, 3)->nullable();
            $table->string('note', 500)->nullable();
            $table->string('receive_note', 500)->nullable();
            $table->uuid('sent_by')->nullable();
            $table->dateTime('sent_at', 6);
            $table->uuid('received_by')->nullable();
            $table->dateTime('received_at', 6)->nullable();
            $table->unsignedBigInteger('seq')->default(0)->index();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
        });

        Schema::create('stock_counts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('site_id');
            $table->uuid('zone_id');
            $table->uuid('product_id');
            $table->date('day');
            $table->integer('counted_bundles');
            $table->integer('system_bundles');
            $table->integer('variance');
            $table->string('reason', 500)->nullable();
            $table->string('status', 16);                       // locked | reopened
            $table->string('reopen_reason', 500)->nullable();
            $table->string('ledger', 32);
            $table->uuid('event_id');
            $table->uuid('by')->nullable();
            $table->dateTime('at', 6);
            $table->unsignedBigInteger('seq')->default(0)->index();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
            $table->index(['site_id', 'day']);
        });
    }

    public function down(): void
    {
        foreach (['stock_counts', 'stock_transfers', 'stock_movements', 'stock_label_positions', 'stock_on_hand'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};

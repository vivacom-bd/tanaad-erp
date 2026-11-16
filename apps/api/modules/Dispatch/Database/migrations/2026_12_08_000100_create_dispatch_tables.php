<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispatches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('site_id')->index();
            $table->string('order_ref', 64);
            $table->string('client_name');
            $table->json('lines');                              // [{product_id, bundles, std_bundle_weight_kg}]
            $table->unsignedInteger('ordered_bundles');
            $table->decimal('expected_kg', 14, 3);
            $table->string('driver', 120);
            $table->string('vehicle', 64);
            $table->string('gate_status', 16);                  // pending | pass | hold | overridden
            $table->decimal('gate_kg', 14, 3)->nullable();
            $table->decimal('variance_pct', 9, 2)->nullable();
            $table->decimal('tolerance_pct', 7, 3)->nullable();
            $table->json('gate_reasons')->nullable();
            $table->uuid('weighed_by')->nullable();
            $table->dateTime('weighed_at', 6)->nullable();
            $table->string('delivery_note_no', 64)->nullable();
            $table->dateTime('gate_passed_at', 6)->nullable();
            $table->uuid('override_by')->nullable();
            $table->string('override_reason', 500)->nullable();
            $table->string('received_by', 120)->nullable();
            $table->unsignedInteger('bundles_received')->nullable();
            $table->integer('discrepancy')->nullable();
            $table->char('signature_hash', 64)->nullable();
            $table->char('photo_hash', 64)->nullable();
            $table->dateTime('delivered_at', 6)->nullable();
            $table->uuid('event_id');
            $table->uuid('created_by')->nullable();
            $table->dateTime('at', 6);
            $table->unsignedBigInteger('seq')->default(0)->index();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
            $table->index(['site_id', 'gate_status']);
            $table->index(['site_id', 'order_ref']);
        });

        Schema::create('dispatch_bundles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('dispatch_id');
            $table->uuid('site_id')->index();
            $table->uuid('label_id')->index();
            $table->string('code', 64);
            $table->uuid('product_id')->nullable();
            $table->uuid('scanned_by')->nullable();
            $table->uuid('event_id');
            $table->dateTime('at', 6);
            $table->unsignedBigInteger('seq')->default(0)->index();
            $table->unique(['dispatch_id', 'label_id']);
        });

        Schema::create('delivery_notes', function (Blueprint $table): void {
            $table->uuid('dispatch_id')->primary();
            $table->uuid('site_id')->index();
            $table->string('number', 64);
            $table->string('via', 16);                          // pass | override
            $table->uuid('issued_by')->nullable();
            $table->uuid('event_id');
            $table->dateTime('at', 6);
            $table->unsignedBigInteger('seq')->default(0)->index();
            $table->unique(['site_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_notes');
        Schema::dropIfExists('dispatch_bundles');
        Schema::dropIfExists('dispatches');
    }
};

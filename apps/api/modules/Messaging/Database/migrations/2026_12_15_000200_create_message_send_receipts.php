<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operational, not a projection: written by DeliverMessage the moment the provider accepts a message, BEFORE the
 * delivery is recorded in the ledger, so a crash or a failing RecordDelivery never leads to a second send.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_send_receipts', function (Blueprint $table): void {
            $table->uuid('message_id')->primary();
            $table->unsignedInteger('attempt');
            $table->string('status', 16);
            $table->string('driver', 32);
            $table->string('provider_id', 191)->nullable();
            $table->dateTime('created_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_send_receipts');
    }
};

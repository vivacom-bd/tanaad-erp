<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Read models written only by the Messaging projectors. */
return new class extends Migration
{
    public function up(): void
    {
        // Where a user can be reached. Owned here so Identity stays untouched; managed by messaging.recipient_saved.
        Schema::create('message_recipients', function (Blueprint $table): void {
            $table->uuid('user_id')->primary();
            $table->string('phone', 32);
            $table->boolean('opted_out')->default(false);
            $table->unsignedBigInteger('seq')->default(0)->index();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
        });

        Schema::create('outbound_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('site_id')->nullable()->index();
            $table->uuid('user_id')->index();
            $table->string('to_phone', 32);
            $table->uuid('source_event_id')->index();
            $table->string('source_type', 64);
            $table->string('template', 128)->nullable();
            $table->string('language', 16)->nullable();
            $table->json('params');
            $table->text('body');
            $table->string('status', 16)->index();              // queued | retrying | logged | delivered | failed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('driver', 32)->nullable();
            $table->string('provider_id', 128)->nullable();
            $table->string('last_error', 500)->nullable();
            $table->dateTime('queued_at', 6);
            $table->dateTime('settled_at', 6)->nullable();
            $table->uuid('event_id');
            $table->unsignedBigInteger('seq')->default(0)->index();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
            $table->index(['site_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_messages');
        Schema::dropIfExists('message_recipients');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Operational telemetry, not business state: one row per device holding its latest report.
        // Not written through the ledger and not rebuilt by Replay.
        Schema::create('device_heartbeats', function (Blueprint $table): void {
            $table->uuid('device_id')->primary();
            $table->foreignUuid('site_id')->constrained('sites');
            $table->string('app_version', 40)->nullable();
            $table->boolean('online')->default(true);
            $table->unsignedInteger('outbox_depth')->default(0);
            $table->dateTime('last_sync_at', 6)->nullable();
            $table->dateTime('reported_at', 6);
            $table->index(['site_id', 'reported_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_heartbeats');
    }
};

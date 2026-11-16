<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // HMAC-SHA256 over the event's facts, written at append (see LedgerChecksum). Null on rows
        // that predate it: events is append-only, so they cannot be back-filled and stay "unverifiable".
        Schema::table('events', function (Blueprint $table): void {
            $table->char('checksum', 64)->nullable()->after('batch_id');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn('checksum');
        });
    }
};

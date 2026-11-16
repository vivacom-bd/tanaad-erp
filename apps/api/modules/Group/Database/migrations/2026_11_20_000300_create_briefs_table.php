<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Morning briefs sent, written only by BriefProjector. Group-wide: site_id stays null (needed by the snapshot reader). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('briefs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('site_id')->nullable()->index();
            $table->date('day')->index();
            $table->string('channel', 16);                      // copy | whatsapp | log
            $table->json('recipient_ids');
            $table->json('lines');
            $table->uuid('sent_by')->nullable();
            $table->dateTime('sent_at', 6);
            $table->uuid('event_id');
            $table->unsignedBigInteger('seq')->default(0)->index();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('briefs');
    }
};

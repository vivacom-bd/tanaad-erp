<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Group read models, written only by ExceptionProjector. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exceptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('site_id')->index();
            $table->string('kind', 32)->index();
            $table->string('severity', 16);                     // info | warning | critical
            $table->string('title', 255);
            $table->string('detail', 2000);
            $table->string('subject_ref', 120)->nullable();
            $table->json('facts')->nullable();
            $table->string('owner_role', 32);
            $table->string('status', 16)->index();              // open | held | approved | rejected | acknowledged | resolved
            $table->uuid('raised_by')->nullable();              // null = system (policy or scheduler)
            $table->dateTime('raised_at', 6);
            $table->uuid('raised_event_id');
            $table->string('decision', 16)->nullable();
            $table->uuid('decided_by')->nullable();
            $table->dateTime('decided_at', 6)->nullable();
            $table->string('decision_note', 1000)->nullable();
            $table->unsignedBigInteger('seq')->default(0)->index();
            $table->dateTime('deleted_at', 6)->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
            $table->index(['site_id', 'kind', 'subject_ref', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exceptions');
    }
};

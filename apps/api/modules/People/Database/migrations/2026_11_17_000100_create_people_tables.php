<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** People read models, written only by PeopleProjector. */
return new class extends Migration
{
    public function up(): void
    {
        $common = function (Blueprint $table): void {
            $table->uuid('site_id')->index();
            $table->unsignedBigInteger('seq')->default(0)->index();
            $table->dateTime('deleted_at', 6)->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
        };

        Schema::create('people', function (Blueprint $table) use ($common): void {
            $table->uuid('id')->primary();
            $common($table);                                   // site_id is the home site
            $table->string('number', 32)->nullable();
            $table->unique(['site_id', 'number']);              // numbers come from a per-site series
            $table->string('full_name');
            $table->string('id_number', 64)->nullable();
            $table->string('phone_momo', 32)->nullable();
            $table->string('sector', 120)->nullable();
            $table->boolean('shared')->default(false);
            $table->json('shared_sites')->nullable();
            $table->string('avatar_seed', 8)->nullable();
            $table->string('status', 16)->index();
            $table->date('joined_on');
            $table->date('archived_on')->nullable();
            $table->string('archive_reason', 255)->nullable();
        });

        Schema::create('engagements', function (Blueprint $table) use ($common): void {
            $table->uuid('id')->primary();
            $common($table);
            $table->uuid('person_id')->index();
            $table->string('type', 16);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->integer('term_days')->nullable();
            $table->string('rate_basis', 16);
            $table->decimal('rate', 16, 2);
            $table->string('currency', 3);
            $table->string('settlement_cycle', 16);
            $table->string('status', 16)->index();
            $table->uuid('renews_engagement_id')->nullable();
            $table->string('end_reason', 255)->nullable();
        });

        Schema::create('attendance', function (Blueprint $table) use ($common): void {
            $table->uuid('id')->primary();
            $common($table);                                   // site_id is the site worked
            $table->uuid('person_id')->index();
            $table->date('day')->index();
            $table->string('shift_kind', 16);
            $table->string('shift_id', 64);
            $table->string('status', 16);
            $table->decimal('overtime_hours', 6, 2)->default(0);
            $table->boolean('cross_site')->default(false);
            $table->uuid('marked_by')->nullable();
            $table->dateTime('marked_at', 6);
            $table->uuid('event_id');                          // last event that set this mark
            $table->unique(['person_id', 'site_id', 'day', 'shift_kind']);
        });
    }

    public function down(): void
    {
        foreach (['attendance', 'engagements', 'people'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};

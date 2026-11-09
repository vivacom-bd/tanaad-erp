<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('legal_name');
            $table->string('short_name', 32);
            $table->string('tagline')->default('');
            $table->char('currency_primary', 3)->default('UGX');
            $table->string('timezone', 64)->default('Africa/Kampala');
            $table->json('shifts');            // [{kind, starts, ends}]
            $table->json('closed_days');       // [0] = Sunday
            $table->string('machine_wall_title')->default('Machine wall');
            $table->text('to_be_confirmed')->nullable();
            $table->unsignedBigInteger('seq')->default(0)->index();
            $table->dateTime('deleted_at', 6)->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 32)->nullable()->unique();   // prototype id such as u_ceo1, for the demo seed
            $table->string('name');
            $table->string('username', 32)->nullable()->unique();   // short sign-in name such as ceo1 or smbags
            $table->string('email')->nullable()->unique();
            $table->string('password')->nullable();
            $table->string('pin_hash')->nullable();
            $table->unsignedSmallInteger('pin_failed_count')->default(0);
            $table->dateTime('pin_locked_until', 6)->nullable();
            $table->uuid('person_id')->nullable();
            $table->string('avatar_seed', 8)->default('');
            $table->boolean('active')->default(true);
            $table->rememberToken();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
        });

        Schema::create('user_site_roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('site_id')->nullable()->constrained('sites');
            $table->string('role', 32);
            $table->unsignedBigInteger('seq')->default(0)->index();
            $table->dateTime('deleted_at', 6)->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();

            $table->unique(['user_id', 'site_id', 'role']);
        });

        Schema::create('devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('site_id')->constrained('sites');
            $table->string('name');
            $table->string('kind', 32)->default('tablet');
            $table->uuid('registered_by')->nullable();
            $table->dateTime('last_seen_at', 6)->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('seq')->default(0)->index();
            $table->dateTime('deleted_at', 6)->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
        Schema::dropIfExists('user_site_roles');
        Schema::dropIfExists('users');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->char('hash', 64);
            $table->string('bucket', 32);
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->uuid('uploaded_by')->nullable();
            $table->uuid('site_id')->nullable();
            $table->dateTime('created_at', 6);
            $table->unique(['bucket', 'hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};

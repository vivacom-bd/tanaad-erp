<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Settlement read models, written only by SettlementProjector. Money is decimal(16,2). */
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

        Schema::create('settlement_runs', function (Blueprint $table) use ($common): void {
            $table->uuid('id')->primary();
            $common($table);
            $table->string('kind', 16);
            $table->date('period_from');
            $table->date('period_to');
            $table->string('currency', 3);
            $table->string('status', 16)->index();            // created, approved, paid, cancelled
            $table->decimal('standard_day_hours', 12, 3);
            $table->decimal('month_working_days', 12, 3);
            $table->unsignedInteger('line_count');
            $table->decimal('total_units', 12, 2);
            $table->decimal('total_gross', 16, 2);
            $table->decimal('total_overtime', 16, 2);
            $table->decimal('total_bonus', 16, 2);
            $table->decimal('total_advances', 16, 2);
            $table->decimal('total_net', 16, 2);
            $table->uuid('created_by')->nullable();
            $table->uuid('created_event_id');
            $table->uuid('approved_by')->nullable();
            $table->dateTime('approved_at', 6)->nullable();
        });

        Schema::create('settlement_lines', function (Blueprint $table) use ($common): void {
            $table->uuid('id')->primary();
            $common($table);
            $table->uuid('settlement_run_id')->index();
            $table->uuid('person_id')->index();
            $table->uuid('engagement_id')->index();
            $table->date('period_from');
            $table->date('period_to');
            $table->string('rate_basis', 16);
            $table->decimal('rate', 16, 2);
            $table->decimal('units', 12, 2);
            $table->decimal('overtime_hours', 8, 2);
            $table->decimal('gross', 16, 2);
            $table->decimal('overtime', 16, 2);
            $table->decimal('bonus', 16, 2);
            $table->decimal('advances_deducted', 16, 2);
            $table->decimal('net', 16, 2);
            $table->json('deductions');                        // [{advance_id, amount}]
            $table->string('status', 16)->index();            // unpaid, paid, cancelled
            $table->uuid('payout_id')->nullable();
        });

        Schema::create('payouts', function (Blueprint $table) use ($common): void {
            $table->uuid('id')->primary();
            $common($table);
            $table->uuid('settlement_run_id')->index();
            $table->uuid('settlement_line_id')->unique();
            $table->uuid('person_id')->index();
            $table->decimal('amount', 16, 2);
            $table->string('method', 16);
            $table->string('reference', 64)->nullable();       // e.g. mobile-money transaction id
            $table->dateTime('paid_at', 6);
            $table->uuid('recorded_by')->nullable();
        });

        Schema::create('advances', function (Blueprint $table) use ($common): void {
            $table->uuid('id')->primary();
            $common($table);
            $table->uuid('person_id')->index();
            $table->decimal('amount', 16, 2);
            $table->decimal('deducted', 16, 2)->default(0);
            $table->decimal('balance', 16, 2);                 // amount - deducted
            $table->string('reason', 255);
            $table->date('given_on');
            $table->uuid('given_by')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['advances', 'payouts', 'settlement_lines', 'settlement_runs'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};

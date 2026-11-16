<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $common = function (Blueprint $table, bool $siteNullable = false): void {
            $siteNullable ? $table->uuid('site_id')->nullable()->index() : $table->uuid('site_id')->index();
            $table->unsignedBigInteger('seq')->default(0)->index();
            $table->dateTime('deleted_at', 6)->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
        };

        Schema::create('site_settings', function (Blueprint $table) use ($common): void {
            $table->uuid('id')->primary();
            $common($table);
            $table->unique('site_id');
            $table->decimal('gate_tolerance_pct', 7, 3)->default(0);
            $table->decimal('mass_gap_pct', 7, 3)->default(0);
            $table->decimal('cash_variance', 16, 2)->default(0);
            $table->decimal('benchmark_pct', 7, 3)->default(0);
            $table->decimal('expense_approval_limit', 16, 2)->default(0);
            $table->decimal('archive_after_absent_days', 12, 3)->default(0);
            $table->decimal('contract_warning_days', 12, 3)->default(0);
            $table->decimal('standard_day_hours', 12, 3)->default(0);
            $table->decimal('month_working_days', 12, 3)->default(0);
            $table->decimal('downtime_reason_after_min', 12, 3)->default(0);
            $table->decimal('material_cost_per_kg', 16, 2)->default(0);
        });

        Schema::create('ref_items', function (Blueprint $table) use ($common): void {
            $table->uuid('id')->primary();
            $common($table, true);
            $table->string('list', 40)->index();
            $table->string('label');
            $table->decimal('value', 16, 3)->nullable();
            $table->string('unit', 32)->nullable();
            $table->string('color', 64)->nullable();
            $table->string('parent_id', 64)->nullable();
            $table->boolean('active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->boolean('system')->default(false);
            $table->boolean('example')->default(false);
            $table->string('code', 64)->nullable()->unique();   // prototype id, e.g. ref_unit_0 or wages
        });

        Schema::create('stages', function (Blueprint $table) use ($common): void {
            $table->uuid('id')->primary();
            $common($table);
            $table->string('code', 32);
            $table->string('name');
            $table->unsignedInteger('sequence');
            $table->string('input_unit', 16);
            $table->string('output_unit', 16);
            $table->string('produces_label', 16)->nullable();
            $table->unique(['site_id', 'code']);
        });

        Schema::create('products', function (Blueprint $table) use ($common): void {
            $table->uuid('id')->primary();
            $table->string('code', 64)->nullable()->unique();
            $common($table);
            $table->string('internal_name');
            $table->string('commercial_name')->nullable();
            $table->string('kind', 16);
            $table->integer('bundle_pieces');
            $table->decimal('std_bundle_weight_kg', 12, 3);
            $table->decimal('weight_tolerance_pct', 7, 3);
            $table->string('weight_status', 16);
            $table->string('colour', 64)->nullable();
            $table->string('unit', 32)->nullable();
            $table->integer('thickness_micron')->nullable();
            $table->boolean('active')->default(true);
        });

        Schema::create('prices', function (Blueprint $table) use ($common): void {
            $table->uuid('id')->primary();
            $common($table);
            $table->uuid('product_id')->index();
            $table->string('channel', 16);
            $table->char('currency', 3);
            $table->decimal('amount', 16, 2);
            $table->date('effective_from');
        });

        Schema::create('machines', function (Blueprint $table) use ($common): void {
            $table->uuid('id')->primary();
            $table->string('code_key', 64)->nullable()->unique();   // prototype id, e.g. m_loom1
            $common($table);
            $table->string('code', 64);
            $table->string('kind', 32);
            $table->string('stage_code', 32);
            $table->string('status', 16);
            $table->string('make')->nullable();
            $table->string('model')->nullable();
            $table->date('commissioned')->nullable();
            $table->decimal('capacity_kg_per_day', 12, 3)->nullable();
        });

        Schema::create('benchmarks', function (Blueprint $table) use ($common): void {
            $table->uuid('id')->primary();
            $common($table);
            $table->uuid('machine_id');
            $table->uuid('product_id');
            $table->decimal('units_per_shift', 12, 3);
            $table->unique(['machine_id', 'product_id']);
        });

        Schema::create('zones', function (Blueprint $table) use ($common): void {
            $table->uuid('id')->primary();
            $common($table);
            $table->string('name');
        });

        Schema::create('fx_rates', function (Blueprint $table) use ($common): void {
            $table->uuid('id')->primary();
            $common($table, true);  // always null: rates are group-wide
            $table->date('day');
            $table->char('base', 3);
            $table->char('quote', 3);
            $table->decimal('rate', 16, 6);
            $table->unique(['day', 'base', 'quote']);
        });

        Schema::create('company_profiles', function (Blueprint $table) use ($common): void {
            $table->uuid('id')->primary();
            $common($table);
            $table->unique('site_id');
            $table->string('address', 500)->default('');
            $table->string('phone')->default('');
            $table->string('email')->default('');
            $table->string('tin')->default('');
            $table->string('bank_details', 1000)->default('');
            $table->string('mobile_money')->default('');
            $table->decimal('vat_rate_pct', 7, 3)->default(0);
            $table->boolean('efris_connected')->default(false);
        });
    }

    public function down(): void
    {
        foreach (['company_profiles', 'fx_rates', 'zones', 'benchmarks', 'machines', 'prices', 'products', 'stages', 'ref_items', 'site_settings'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};

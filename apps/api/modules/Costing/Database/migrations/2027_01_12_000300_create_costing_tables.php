<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Costing read tables. CostingProjector is their only writer; all rebuild from the ledger. */
return new class extends Migration
{
    public function up(): void
    {
        $common = function (Blueprint $t): void {
            $t->unsignedBigInteger('seq')->default(0)->index();
            $t->dateTime('created_at', 6)->nullable();
            $t->dateTime('updated_at', 6)->nullable();
        };

        // One spend line per source fact. source_key is what cost_category_map maps (cash:<category>, payout:cash, ...).
        Schema::create('costing_cost_lines', function (Blueprint $t) use ($common): void {
            $t->uuid('id')->primary();
            $t->uuid('site_id');
            $t->date('day');
            $t->string('source_key', 80);
            $t->boolean('cash')->default(true);
            $t->decimal('amount_ugx', 18, 2);
            $t->uuid('machine_id')->nullable();
            $t->uuid('product_id')->nullable();
            $t->uuid('superseded_by')->nullable();
            $t->uuid('event_id');
            $t->index(['site_id', 'day']);
            $common($t);
        });

        Schema::create('costing_revenue_lines', function (Blueprint $t) use ($common): void {
            $t->string('id', 80)->primary();
            $t->uuid('site_id');
            $t->date('day');
            $t->string('source', 16);   // cash_sale | order_cash | invoice
            $t->decimal('amount', 18, 2);
            $t->char('currency', 3);
            $t->decimal('amount_ugx', 18, 2)->nullable();   // null: foreign currency without a rate on the fact; converted at close
            $t->uuid('superseded_by')->nullable();
            $t->uuid('event_id');
            $t->index(['site_id', 'day']);
            $common($t);
        });

        Schema::create('costing_output_lines', function (Blueprint $t) use ($common): void {
            $t->uuid('id')->primary();   // production entry_id: a replaced entry overwrites its line
            $t->uuid('site_id');
            $t->date('day');
            $t->uuid('product_id')->nullable();
            $t->string('stage_code', 32);
            $t->decimal('good_units', 16, 3);
            $t->decimal('output_kg', 16, 3);
            $t->uuid('event_id');
            $t->index(['site_id', 'day']);
            $common($t);
        });

        Schema::create('costing_material_receipts', function (Blueprint $t) use ($common): void {
            $t->uuid('id')->primary();   // purchase_id
            $t->uuid('site_id')->index();
            $t->date('day');
            $t->decimal('received_kg', 16, 3);
            $t->decimal('total_ugx', 18, 2);
            $t->uuid('event_id');
            $common($t);
        });

        Schema::create('costing_closed_days', function (Blueprint $t) use ($common): void {
            $t->uuid('site_id');
            $t->date('day');
            $t->boolean('closed');
            $t->uuid('event_id');
            $t->primary(['site_id', 'day']);
            $common($t);
        });

        Schema::create('cost_category_map', function (Blueprint $t) use ($common): void {
            $t->uuid('id')->primary();
            $t->uuid('site_id');
            $t->string('source_key', 80);
            $t->string('cost_category', 64);   // an expense_category code, or "excluded"
            $t->uuid('event_id');
            $t->unique(['site_id', 'source_key']);
            $common($t);
        });

        Schema::create('costing_periods', function (Blueprint $t) use ($common): void {
            $t->uuid('id')->primary();
            $t->uuid('site_id');
            $t->string('label', 32);
            $t->date('from_day');
            $t->date('to_day');
            $t->string('status', 16);   // closed | reopened
            $t->unsignedInteger('version');
            $t->json('snapshot')->nullable();
            $t->uuid('closed_by')->nullable();
            $t->dateTime('closed_at', 6)->nullable();
            $t->string('reopen_reason', 1000)->nullable();
            $t->uuid('event_id');
            $t->unique(['site_id', 'from_day', 'to_day']);
            $common($t);
        });
    }

    public function down(): void
    {
        foreach (['costing_periods', 'cost_category_map', 'costing_closed_days', 'costing_material_receipts', 'costing_output_lines', 'costing_revenue_lines', 'costing_cost_lines'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

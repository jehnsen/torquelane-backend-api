<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vendors (the provider's approved third-party list) and each customer
 * account's own spare parts — NOT shop inventory (Phase 6).
 *
 *  - a fleet part belongs to one customer account (stock is per account,
 *    never pooled); its SKU is unique within the account;
 *  - `current_stock` never goes negative (CHECK) and changes only through
 *    an action (receiving a purchase order);
 *  - `fleet_part_usages` says which service tasks consume the part, and how
 *    many per service: the forecast's input. `position` keeps the order
 *    usages are met in, which the forecast's ranking ties keep.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['organization_id', 'name']);
        });

        Schema::create('fleet_parts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('customer_account_id')->index();
            $table->string('sku', 64);
            $table->string('name');
            $table->string('category', 16)->default('other');
            $table->string('unit', 16)->default('piece');
            $table->cents('unit_cost_cents')->default(0);
            $table->integer('current_stock')->default(0);
            $table->integer('reorder_point')->default(0);
            $table->string('preferred_vendor')->default('');
            $table->smallInteger('lead_time_days')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestampsTz();

            $table->unique(['customer_account_id', 'sku']);
            $table->unique(['id', 'organization_id']);
            $table->unique(['id', 'customer_account_id']);
            $table->foreign(['customer_account_id', 'organization_id'])->references(['id', 'organization_id'])->on('customer_accounts')->restrictOnDelete();
        });
        DB::statement('alter table fleet_parts add constraint fleet_parts_counts_check check (current_stock >= 0 and reorder_point >= 0 and lead_time_days >= 0 and unit_cost_cents >= 0)');

        Schema::create('fleet_part_usages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('fleet_part_id')->index();
            $table->ulid('service_task_id')->index();
            $table->integer('quantity_per_service');
            $table->unsignedInteger('position')->default(0);
            $table->timestampsTz();

            $table->unique(['fleet_part_id', 'service_task_id']);
            $table->foreign(['fleet_part_id', 'organization_id'])->references(['id', 'organization_id'])->on('fleet_parts')->cascadeOnDelete();
            $table->foreign(['service_task_id', 'organization_id'])->references(['id', 'organization_id'])->on('service_tasks')->cascadeOnDelete();
        });
        DB::statement('alter table fleet_part_usages add constraint fleet_part_usages_quantity_check check (quantity_per_service > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_part_usages');
        Schema::dropIfExists('fleet_parts');
        Schema::dropIfExists('vendors');
    }
};

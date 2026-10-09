<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The shop's own inventory, master data (Phase 6): items, their per-branch
 * settings and the stock locations of each branch. (The customer-side
 * `fleet_parts` catalogue is untouched.)
 *
 * Invariants the database holds:
 *  - an item's SKU is unique per organization, case-insensitively, and so is
 *    its barcode where it has one;
 *  - a service fee is never stocked; an item with no purchase unit has a
 *    purchase factor of 1;
 *  - an item is set up once per branch (reorder point and quantity, bin,
 *    price override) and the branch is the item's own organization's;
 *  - every branch has exactly one 'store' location (backfilled here, created
 *    with each new branch).
 *
 * Also: `branches.negative_stock_policy` (what a move past zero does) and the
 * composite keys that later tables point at (`vendors`, `work_order_lines`).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table vendors add constraint vendors_id_organization_unique unique (id, organization_id)');
        DB::statement('alter table work_order_lines add constraint work_order_lines_id_organization_unique unique (id, organization_id)');

        Schema::table('branches', function (Blueprint $table) {
            $table->string('negative_stock_policy', 16)->default('allow_and_flag');
        });
        DB::statement("alter table branches add constraint branches_negative_stock_policy_check check (negative_stock_policy in ('allow_and_flag', 'block'))");

        Schema::create('items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->string('sku', 64);
            $table->string('barcode', 64)->nullable();
            $table->string('name');
            $table->string('item_type', 16);
            $table->string('category', 64)->default('');
            $table->string('uom', 16);
            $table->string('purchase_uom', 16)->nullable();
            $table->quantity('purchase_uom_factor')->default(1);
            $table->string('tax_class', 16)->default('vatable');
            $table->cents('default_price_cents')->default(0);
            $table->boolean('is_stocked')->default(true);
            $table->boolean('is_active')->default(true);
            $table->ulid('preferred_vendor_id')->nullable()->index();
            $table->timestampsTz();

            $table->unique(['id', 'organization_id']);
        });
        DB::statement('create unique index items_sku_unique on items (organization_id, lower(sku))');
        DB::statement('create unique index items_barcode_unique on items (organization_id, lower(barcode)) where barcode is not null');
        // A vendor leaving the list only clears the preference, never the item's organization.
        DB::statement('alter table items add constraint items_preferred_vendor_fk foreign key (preferred_vendor_id, organization_id) references vendors (id, organization_id) on delete set null (preferred_vendor_id)');
        DB::statement("alter table items add constraint items_type_check check (item_type in ('part', 'consumable', 'retail', 'ingredient', 'service_fee'))");
        DB::statement("alter table items add constraint items_tax_class_check check (tax_class in ('vatable', 'vat_exempt', 'zero_rated'))");
        DB::statement('alter table items add constraint items_values_check check (default_price_cents >= 0 and purchase_uom_factor > 0 and (purchase_uom is not null or purchase_uom_factor = 1))');
        DB::statement("alter table items add constraint items_service_fee_check check (item_type <> 'service_fee' or not is_stocked)");

        Schema::create('item_branch_settings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('item_id');
            $table->ulid('branch_id')->index();
            $table->quantity('reorder_point')->nullable();
            $table->quantity('reorder_qty')->nullable();
            $table->string('bin', 32)->nullable();
            $table->cents('price_override_cents')->nullable();
            $table->timestampsTz();

            $table->unique(['item_id', 'branch_id']);
            $table->foreign(['item_id', 'organization_id'])->references(['id', 'organization_id'])->on('items')->cascadeOnDelete();
            $table->foreign(['branch_id', 'organization_id'])->references(['id', 'organization_id'])->on('branches')->restrictOnDelete();
        });
        DB::statement('alter table item_branch_settings add constraint item_branch_settings_values_check check (coalesce(reorder_point, 0) >= 0 and coalesce(reorder_qty, 0) >= 0 and coalesce(price_override_cents, 0) >= 0)');

        Schema::create('stock_locations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('branch_id')->index();
            $table->string('kind', 16)->default('store');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['id', 'organization_id']);
            // The composite a balance or move points at, so its branch can never disagree with its location's.
            $table->unique(['id', 'branch_id']);
            $table->foreign(['branch_id', 'organization_id'])->references(['id', 'organization_id'])->on('branches')->restrictOnDelete();
        });
        DB::statement("alter table stock_locations add constraint stock_locations_kind_check check (kind in ('store'))");
        DB::statement("create unique index stock_locations_one_store_per_branch on stock_locations (branch_id) where kind = 'store'");

        // One store per existing branch.
        $now = now('UTC');
        foreach (DB::table('branches')->get(['id', 'organization_id', 'name']) as $branch) {
            DB::table('stock_locations')->insert([
                'id' => strtolower((string) Str::ulid()),
                'organization_id' => $branch->organization_id,
                'branch_id' => $branch->id,
                'kind' => 'store',
                'name' => sprintf('%s store', is_string($branch->name) ? $branch->name : 'Branch'),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_locations');
        Schema::dropIfExists('item_branch_settings');
        Schema::dropIfExists('items');
        DB::statement('alter table branches drop constraint if exists branches_negative_stock_policy_check');
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('negative_stock_policy');
        });
        DB::statement('alter table work_order_lines drop constraint if exists work_order_lines_id_organization_unique');
        DB::statement('alter table vendors drop constraint if exists vendors_id_organization_unique');
    }
};

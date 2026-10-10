<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Detailing (Phase 9): each branch's settings and its menu.
 *
 * The source was one shop: a `settings` singleton and global `services`,
 * `service_categories` and `addon_catalog`, with a package's size carried in
 * its name ("The Essential (M)"). Here every row belongs to a branch, and a
 * package or add-on has ONE row with a price per size class.
 *
 * Invariants the database holds:
 *  - a branch has at most one settings row; its pool holds at least one
 *    detailer, a booking takes at least one and never more than the pool by
 *    default, and closing is a minute of the day;
 *  - a package needs at least one detailer and recommends no fewer than it needs;
 *  - a price is per (package, size class) and per (add-on, size class), once;
 *  - a package's bill of materials names an item once, with a quantity above zero;
 *  - a category, package or add-on name is unique within its branch, whatever its case.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A technician is named on bookings of their own branch.
        DB::statement('alter table technicians add constraint technicians_id_branch_unique unique (id, branch_id)');
        DB::statement('alter table technicians add constraint technicians_id_organization_unique unique (id, organization_id)');

        Schema::create('detailing_settings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('branch_id')->unique();
            $table->unsignedSmallInteger('detailer_pool_size')->default(5);
            $table->unsignedSmallInteger('default_detailers_per_booking')->default(1);
            // Minutes since midnight in the branch's day (1020 = 5:00 PM).
            $table->unsignedSmallInteger('closing_minutes')->default(1020);
            $table->boolean('public_booking_enabled')->default(false);
            $table->text('public_booking_notice')->nullable();
            // A job may not start before its check-in inspection, unless overridden with a reason.
            $table->boolean('inspection_required')->default(true);
            // How much of a package's materials each size class uses: {"small": "0.800", …}.
            $table->jsonb('size_multipliers');
            $table->timestampsTz();

            $table->foreign(['branch_id', 'organization_id'])->references(['id', 'organization_id'])->on('branches')->restrictOnDelete();
        });
        DB::statement(<<<'SQL'
            alter table detailing_settings add constraint detailing_settings_values_check check (
                detailer_pool_size >= 1
                and default_detailers_per_booking between 1 and detailer_pool_size
                and closing_minutes between 1 and 1439
                and jsonb_typeof(size_multipliers) = 'object'
            )
            SQL);

        Schema::create('detailing_categories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('branch_id')->index();
            $table->string('name');
            $table->string('slug');
            // A colour token the screens map to a chip, never a class name.
            $table->string('color', 32)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestampsTz();

            $table->unique(['id', 'branch_id']);
            $table->foreign(['branch_id', 'organization_id'])->references(['id', 'organization_id'])->on('branches')->restrictOnDelete();
        });
        DB::statement('create unique index detailing_categories_slug_unique on detailing_categories (branch_id, lower(slug))');

        Schema::create('detailing_packages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('branch_id')->index();
            $table->ulid('category_id')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            // As the shop types it ("4–5 hrs", "1–2 days"); read by ServiceDuration.
            $table->string('duration_text', 64);
            // What the package includes: the job card's checklist.
            $table->jsonb('inclusions')->default('[]');
            $table->boolean('is_popular')->default(false);
            $table->unsignedSmallInteger('min_detailers')->default(1);
            $table->unsignedSmallInteger('recommended_detailers')->default(1);
            $table->string('applies_to', 16)->default('all');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestampsTz();

            $table->unique(['id', 'organization_id']);
            $table->unique(['id', 'branch_id']);
            $table->foreign(['branch_id', 'organization_id'])->references(['id', 'organization_id'])->on('branches')->restrictOnDelete();
            $table->foreign(['category_id', 'branch_id'])->references(['id', 'branch_id'])->on('detailing_categories')->restrictOnDelete();
        });
        DB::statement('create unique index detailing_packages_name_unique on detailing_packages (branch_id, lower(name))');
        DB::statement(<<<'SQL'
            alter table detailing_packages add constraint detailing_packages_values_check check (
                min_detailers >= 1 and recommended_detailers >= min_detailers
                and applies_to in ('all', 'four_wheel', 'motorcycle')
                and jsonb_typeof(inclusions) = 'array'
                and length(btrim(duration_text)) > 0
            )
            SQL);

        Schema::create('detailing_package_prices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('package_id');
            $table->string('size_class', 8);
            $table->cents('price_cents');
            // A size that takes longer than the package's own duration says so here.
            $table->string('duration_text', 64)->nullable();
            $table->timestampsTz();

            $table->unique(['package_id', 'size_class']);
            $table->foreign(['package_id', 'organization_id'])->references(['id', 'organization_id'])->on('detailing_packages')->cascadeOnDelete();
        });
        DB::statement("alter table detailing_package_prices add constraint detailing_package_prices_values_check check (size_class in ('small', 'medium', 'large', 'xl') and price_cents >= 0)");

        Schema::create('detailing_package_materials', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('package_id');
            $table->ulid('item_id');
            // What a MEDIUM vehicle uses, in the item's stock unit.
            $table->quantity('quantity');
            // False for what a job uses one of whatever the size (a clay bar, a pad).
            $table->boolean('scales_with_size')->default(true);
            $table->timestampsTz();

            $table->unique(['package_id', 'item_id']);
            $table->foreign(['package_id', 'organization_id'])->references(['id', 'organization_id'])->on('detailing_packages')->cascadeOnDelete();
            $table->foreign(['item_id', 'organization_id'])->references(['id', 'organization_id'])->on('items')->restrictOnDelete();
        });
        DB::statement('alter table detailing_package_materials add constraint detailing_package_materials_quantity_check check (quantity > 0)');

        Schema::create('detailing_addons', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('branch_id')->index();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestampsTz();

            $table->unique(['id', 'organization_id']);
            $table->unique(['id', 'branch_id']);
            $table->foreign(['branch_id', 'organization_id'])->references(['id', 'organization_id'])->on('branches')->restrictOnDelete();
        });
        DB::statement('create unique index detailing_addons_name_unique on detailing_addons (branch_id, lower(name))');

        Schema::create('detailing_addon_prices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('addon_id');
            $table->string('size_class', 8);
            $table->cents('price_cents');
            $table->timestampsTz();

            $table->unique(['addon_id', 'size_class']);
            $table->foreign(['addon_id', 'organization_id'])->references(['id', 'organization_id'])->on('detailing_addons')->cascadeOnDelete();
        });
        DB::statement("alter table detailing_addon_prices add constraint detailing_addon_prices_values_check check (size_class in ('small', 'medium', 'large', 'xl') and price_cents >= 0)");
    }

    public function down(): void
    {
        Schema::dropIfExists('detailing_addon_prices');
        Schema::dropIfExists('detailing_addons');
        Schema::dropIfExists('detailing_package_materials');
        Schema::dropIfExists('detailing_package_prices');
        Schema::dropIfExists('detailing_packages');
        Schema::dropIfExists('detailing_categories');
        Schema::dropIfExists('detailing_settings');
        DB::statement('alter table technicians drop constraint if exists technicians_id_organization_unique');
        DB::statement('alter table technicians drop constraint if exists technicians_id_branch_unique');
    }
};

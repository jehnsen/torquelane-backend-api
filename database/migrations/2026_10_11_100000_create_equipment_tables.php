<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Equipment monitoring (Phase 10): the detailing floor's tool register, its
 * usage log, its service history, and which packages put how many hours on
 * which tool. The source's `shop_equipment`, `equipment_usage_logs`,
 * `equipment_maintenance_logs` and `service_equipment`, per branch.
 *
 * **There is no meter column, and nothing is posted to `meter_readings`.** A
 * tool's runtime is derived on read (its baseline, the hours its package
 * profiles put on it for each completed job, and its usage log), as in the
 * source. `last_service_at` is the one clock that is stored: it restarts every
 * service interval.
 *
 * Invariants the database holds:
 *  - a tool belongs to one branch, its name unique there whatever its case;
 *    its bay, its logs and its package profiles are of that same branch
 *    (composite keys);
 *  - category, status, condition, meter type and service kind are the
 *    source's lists; intervals, baselines, costs and hours are never negative;
 *  - a usage entry logs runtime hours or job cycles (not neither);
 *  - a package maps a tool once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('branch_id')->index();
            $table->string('name');
            $table->string('category', 16)->default('other');
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable();
            $table->ulid('bay_id')->nullable();
            $table->string('status', 16)->default('available');
            $table->string('condition_rating', 16)->default('good');
            $table->date('purchase_date')->nullable();
            $table->cents('purchase_cost_cents')->default(0);
            $table->date('warranty_until')->nullable();
            // Rated total service life; null = not forecast.
            $table->decimal('expected_life_hours', 14, 3)->nullable();
            $table->string('meter_type', 8)->default('hours');
            // What was already on the clock when the tool was registered.
            $table->decimal('baseline_hours', 14, 3)->default(0);
            $table->unsignedInteger('baseline_cycles')->default(0);
            // Preventive-maintenance intervals: any or all; the first to trip binds.
            $table->decimal('service_interval_hours', 14, 3)->nullable();
            $table->unsignedInteger('service_interval_cycles')->nullable();
            $table->unsignedInteger('service_interval_days')->nullable();
            // Restarts every interval; null = never serviced.
            $table->timestampTz('last_service_at')->nullable();
            $table->text('maintenance_task')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestampsTz();

            $table->unique(['id', 'organization_id']);
            $table->unique(['id', 'branch_id']);
            $table->foreign(['branch_id', 'organization_id'])->references(['id', 'organization_id'])->on('branches')->restrictOnDelete();
            $table->foreign(['bay_id', 'branch_id'])->references(['id', 'branch_id'])->on('bays')->restrictOnDelete();
        });
        DB::statement('create unique index equipment_name_unique on equipment (branch_id, lower(name))');
        DB::statement(<<<'SQL'
            alter table equipment add constraint equipment_values_check check (
                category in ('wash', 'polishing', 'interior', 'coating', 'air', 'facility', 'measurement', 'other')
                and status in ('available', 'in_use', 'maintenance', 'repair', 'retired')
                and condition_rating in ('excellent', 'good', 'fair', 'poor')
                and meter_type in ('hours', 'cycles', 'days')
                and purchase_cost_cents >= 0 and baseline_hours >= 0
                and (expected_life_hours is null or expected_life_hours > 0)
                and (service_interval_hours is null or service_interval_hours > 0)
                and (service_interval_cycles is null or service_interval_cycles > 0)
                and (service_interval_days is null or service_interval_days > 0)
            )
            SQL);

        Schema::create('equipment_usage_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('branch_id');
            $table->ulid('equipment_id');
            // The job it was for, when it was one the booking sheet did not already count.
            $table->ulid('detailing_booking_id')->nullable();
            $table->decimal('hours', 14, 3)->default(0);
            $table->unsignedInteger('cycles')->default(0);
            $table->timestampTz('used_at');
            $table->text('note')->nullable();
            $table->ulid('logged_by')->nullable();
            $table->string('logged_by_name');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['equipment_id', 'used_at']);
            $table->foreign(['equipment_id', 'branch_id'])->references(['id', 'branch_id'])->on('equipment')->cascadeOnDelete();
            $table->foreign(['detailing_booking_id', 'branch_id'])->references(['id', 'branch_id'])->on('detailing_bookings')->restrictOnDelete();
            $table->foreign(['logged_by', 'organization_id'])->references(['id', 'organization_id'])->on('users')->restrictOnDelete();
        });
        DB::statement('alter table equipment_usage_logs add constraint equipment_usage_logs_values_check check (hours >= 0 and (hours > 0 or cycles > 0))');

        Schema::create('equipment_maintenance_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('branch_id');
            $table->ulid('equipment_id');
            $table->string('kind', 24)->default('preventive');
            $table->timestampTz('performed_at');
            // Who did the work, as written: a technician or an outside shop.
            $table->string('performed_by')->nullable();
            $table->cents('cost_cents')->default(0);
            $table->decimal('downtime_hours', 14, 3)->default(0);
            $table->text('parts')->nullable();
            $table->text('notes')->nullable();
            // What the tool's derived meters read when the service was logged.
            $table->decimal('meter_hours', 14, 3)->nullable();
            $table->unsignedInteger('meter_cycles')->nullable();
            // False for a clean inspection: the service intervals keep running.
            $table->boolean('resets_service')->default(true);
            $table->ulid('recorded_by')->nullable();
            $table->string('recorded_by_name');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['equipment_id', 'performed_at']);
            $table->foreign(['equipment_id', 'branch_id'])->references(['id', 'branch_id'])->on('equipment')->cascadeOnDelete();
            $table->foreign(['recorded_by', 'organization_id'])->references(['id', 'organization_id'])->on('users')->restrictOnDelete();
        });
        DB::statement(<<<'SQL'
            alter table equipment_maintenance_logs add constraint equipment_maintenance_logs_values_check check (
                kind in ('preventive', 'repair', 'inspection', 'calibration', 'part_replacement')
                and cost_cents >= 0 and downtime_hours >= 0
            )
            SQL);

        Schema::create('equipment_package_profiles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('branch_id');
            $table->ulid('package_id')->index();
            $table->ulid('equipment_id')->index();
            // Runtime one completed job of the package puts on the tool.
            $table->decimal('hours_per_job', 14, 3)->default(0);
            $table->timestampsTz();

            $table->unique(['package_id', 'equipment_id']);
            $table->foreign(['package_id', 'branch_id'])->references(['id', 'branch_id'])->on('detailing_packages')->cascadeOnDelete();
            $table->foreign(['equipment_id', 'branch_id'])->references(['id', 'branch_id'])->on('equipment')->cascadeOnDelete();
        });
        DB::statement('alter table equipment_package_profiles add constraint equipment_package_profiles_hours_check check (hours_per_job >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_package_profiles');
        Schema::dropIfExists('equipment_maintenance_logs');
        Schema::dropIfExists('equipment_usage_logs');
        Schema::dropIfExists('equipment');
    }
};

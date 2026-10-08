<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The PMS catalogue (organization-wide, as in the frontend: every customer
 * beneath an organization is measured against the same schedule) and the
 * per asset × task "last done" state that replaces the frontend's
 * `task_state` jsonb.
 *
 * Editing a task's interval never touches maintenance_states: due dates move
 * because they are derived, the recorded history does not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_tasks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            // Stable slug, e.g. oil-filter.
            $table->string('code', 64);
            $table->string('name');
            $table->string('category', 16);
            $table->unsignedInteger('interval_km');
            $table->unsignedSmallInteger('interval_months');
            $table->cents('estimated_cost_cents')->default(0);
            $table->rate('estimated_hours')->default(0);
            $table->boolean('critical')->default(false);
            $table->boolean('is_active')->default(true);
            // Evaluation order: ties in urgency keep catalogue order.
            $table->unsignedInteger('position')->default(0);
            $table->timestampsTz();

            $table->unique(['organization_id', 'code']);
            $table->unique(['id', 'organization_id']);
        });

        DB::statement("alter table service_tasks add constraint service_tasks_category_check check (category in ('engine', 'drivetrain', 'brakes', 'tires', 'electrical', 'safety', 'body'))");
        DB::statement('alter table service_tasks add constraint service_tasks_interval_check check (interval_km > 0 and interval_months > 0)');
        DB::statement('alter table service_tasks add constraint service_tasks_cost_check check (estimated_cost_cents >= 0 and estimated_hours >= 0)');

        Schema::create('maintenance_states', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->string('asset_type', 16);
            $table->ulid('asset_id');
            $table->ulid('vehicle_id')->nullable();
            $table->ulid('service_task_id')->index();
            $table->string('meter_kind', 8);
            $table->quantity('last_done_value');
            $table->date('last_done_on');
            $table->timestampsTz();

            $table->unique(['asset_type', 'asset_id', 'service_task_id']);
            $table->foreign(['vehicle_id', 'organization_id'])->references(['id', 'organization_id'])->on('vehicles')->restrictOnDelete();
            $table->foreign(['service_task_id', 'organization_id'])->references(['id', 'organization_id'])->on('service_tasks')->restrictOnDelete();
        });

        DB::statement("alter table maintenance_states add constraint maintenance_states_asset_check check (asset_type in ('vehicle') and (asset_type <> 'vehicle' or vehicle_id = asset_id))");
        DB::statement("alter table maintenance_states add constraint maintenance_states_kind_check check (meter_kind in ('km', 'hours', 'cycles', 'cups'))");
        DB::statement('alter table maintenance_states add constraint maintenance_states_value_check check (last_done_value >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_states');
        Schema::dropIfExists('service_tasks');
    }
};

<?php

declare(strict_types=1);

use App\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Meter readings (append-only, R7). The asset is polymorphic (`asset_type`,
 * `asset_id`: vehicles now, equipment in Phase 10), with a typed nullable FK
 * per asset kind (`vehicle_id`) so the database still guarantees the asset
 * exists in the same organization.
 *
 * Nothing is edited or deleted. A wrong reading is corrected by a VOID row
 * (`voids_reading_id`, no value), and the effective readings are the ones no
 * void row points at. Current reading and daily rate are derived from them
 * (App\Domain\Maintenance\MeterRate).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meter_readings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->string('asset_type', 16);
            $table->ulid('asset_id');
            $table->ulid('vehicle_id')->nullable();
            $table->string('meter_kind', 8);
            // Null only on a void row.
            $table->quantity('value')->nullable();
            $table->date('read_on');
            $table->string('source', 16);
            $table->ulid('recorded_by')->nullable();
            $table->ulid('voids_reading_id')->nullable()->unique();
            $table->text('void_reason')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['asset_type', 'asset_id', 'meter_kind', 'read_on']);
            $table->foreign(['vehicle_id', 'organization_id'])->references(['id', 'organization_id'])->on('vehicles')->restrictOnDelete();
            $table->foreign(['recorded_by', 'organization_id'])->references(['id', 'organization_id'])->on('users')->restrictOnDelete();
        });

        // After the create: a self-reference needs the primary key to exist first.
        Schema::table('meter_readings', function (Blueprint $table) {
            $table->foreign('voids_reading_id')->references('id')->on('meter_readings')->restrictOnDelete();
        });

        DB::statement("alter table meter_readings add constraint meter_readings_asset_check check (asset_type in ('vehicle') and (asset_type <> 'vehicle' or vehicle_id = asset_id))");
        DB::statement("alter table meter_readings add constraint meter_readings_kind_check check (meter_kind in ('km', 'hours', 'cycles', 'cups'))");
        DB::statement("alter table meter_readings add constraint meter_readings_source_check check (source in ('manual', 'check_in', 'work_order', 'import', 'telematics', 'correction'))");
        DB::statement('alter table meter_readings add constraint meter_readings_value_check check ((voids_reading_id is null) = (value is not null) and (value is null or value >= 0))');

        AppendOnly::protect('meter_readings');
    }

    public function down(): void
    {
        AppendOnly::unprotect('meter_readings');
        Schema::dropIfExists('meter_readings');
    }
};

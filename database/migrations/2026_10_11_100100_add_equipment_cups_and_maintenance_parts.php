<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Equipment monitoring (Phase 10), second part: a tool whose count meter
 * counts CUPS (the espresso machine), and service entries that take parts
 * from stock.
 *
 * Invariants the database holds:
 *  - a tool is metered in hours, cycles, cups or days, and may be café gear;
 *  - a part issued to a service entry names a stocked item once per entry,
 *    with a quantity above zero, what it was worth when it left the shelf,
 *    and the stock move that took it;
 *  - a stock move may come from an equipment service entry
 *    (`equipment_maintenance`), which then must name it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table equipment drop constraint equipment_values_check');
        DB::statement(self::equipmentCheck("'wash', 'polishing', 'interior', 'coating', 'air', 'facility', 'measurement', 'cafe', 'other'", "'hours', 'cycles', 'cups', 'days'"));

        Schema::table('equipment_maintenance_logs', function (Blueprint $table) {
            // What the parts taken from stock were worth; `cost_cents` is everything else.
            $table->cents('parts_cost_cents')->default(0);
            $table->unique(['id', 'organization_id']);
        });
        DB::statement('alter table equipment_maintenance_logs add constraint equipment_maintenance_logs_parts_cost_check check (parts_cost_cents >= 0)');

        Schema::create('equipment_maintenance_parts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('maintenance_log_id')->index();
            $table->ulid('item_id');
            $table->quantity('quantity');
            // The average cost it left the shelf at, and quantity × that.
            $table->cents('unit_cost_cents');
            $table->cents('value_cents');
            $table->foreignUlid('stock_move_id')->constrained('stock_moves')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['maintenance_log_id', 'item_id']);
            $table->foreign(['maintenance_log_id', 'organization_id'])->references(['id', 'organization_id'])->on('equipment_maintenance_logs')->cascadeOnDelete();
            $table->foreign(['item_id', 'organization_id'])->references(['id', 'organization_id'])->on('items')->restrictOnDelete();
        });
        DB::statement('alter table equipment_maintenance_parts add constraint equipment_maintenance_parts_values_check check (quantity > 0 and unit_cost_cents >= 0 and value_cents >= 0)');

        DB::statement('alter table stock_moves drop constraint stock_moves_source_check');
        DB::statement("alter table stock_moves add constraint stock_moves_source_check check (source_type in ('manual', 'goods_receipt', 'work_order_line', 'stock_count', 'stock_transfer', 'detailing_booking', 'equipment_maintenance') and (source_type = 'manual' or source_id is not null))");
    }

    public function down(): void
    {
        DB::statement('alter table stock_moves drop constraint stock_moves_source_check');
        DB::statement("alter table stock_moves add constraint stock_moves_source_check check (source_type in ('manual', 'goods_receipt', 'work_order_line', 'stock_count', 'stock_transfer', 'detailing_booking') and (source_type = 'manual' or source_id is not null))");

        Schema::dropIfExists('equipment_maintenance_parts');
        DB::statement('alter table equipment_maintenance_logs drop constraint if exists equipment_maintenance_logs_parts_cost_check');
        Schema::table('equipment_maintenance_logs', function (Blueprint $table) {
            $table->dropUnique(['id', 'organization_id']);
            $table->dropColumn('parts_cost_cents');
        });

        DB::statement('alter table equipment drop constraint equipment_values_check');
        DB::statement(self::equipmentCheck("'wash', 'polishing', 'interior', 'coating', 'air', 'facility', 'measurement', 'other'", "'hours', 'cycles', 'days'"));
    }

    private static function equipmentCheck(string $categories, string $meterTypes): string
    {
        return <<<SQL
            alter table equipment add constraint equipment_values_check check (
                category in ({$categories})
                and status in ('available', 'in_use', 'maintenance', 'repair', 'retired')
                and condition_rating in ('excellent', 'good', 'fair', 'poor')
                and meter_type in ({$meterTypes})
                and purchase_cost_cents >= 0 and baseline_hours >= 0
                and (expected_life_hours is null or expected_life_hours > 0)
                and (service_interval_hours is null or service_interval_hours > 0)
                and (service_interval_cycles is null or service_interval_cycles > 0)
                and (service_interval_days is null or service_interval_days > 0)
            )
            SQL;
    }
};

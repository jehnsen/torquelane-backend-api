<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Actions\Equipment\ManageEquipment;
use App\Models\Branch;
use App\Models\DetailingPackage;
use App\Models\Equipment;
use App\Models\User;
use App\Tenancy\TenantContextResolver;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Equipment at Samahuzai-Biñan (Phase 10): the source's own register of the
 * 22 tools a detailing floor runs, each with its rated interval and service
 * task; which packages put hours on a handful of them; and a little history
 * (one service, one logged run) so the monitor has something to show.
 *
 * Ids: `equipment:<slug>`.
 */
final class EquipmentSeed
{
    /**
     * slug, name, category, brand, meter type, interval hours, cycles, days, rated life hours, service task
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string|null, 4: string, 5: string|null, 6: int|null, 7: int|null, 8: string|null, 9: string}>
     */
    private const array TOOLS = [
        ['pressure-washer', 'High-Pressure Washer', 'wash', 'Kranzle', 'hours', '250', null, null, '4000', 'Pump oil change - nozzle wear + unloader valve check'],
        ['foam-cannon', 'Foam Cannon / Snow Foam Lance', 'wash', 'MTM Hydro', 'cycles', null, 500, null, null, 'Replace mesh filter + O-ring set'],
        ['car-dryer', 'Car Dryer / Filtered Air Blower', 'wash', 'Metro Vac', 'hours', '100', null, null, '2000', 'Clean intake filter - check motor carbon brushes'],
        ['di-vessel', 'Water Deionizer (DI) Vessel', 'facility', null, 'cycles', null, 300, null, null, 'Replace DI resin (or when rinse TDS exceeds 10 ppm)'],
        ['air-compressor', 'Air Compressor (belt-drive)', 'air', 'Ingersoll Rand', 'hours', '500', null, null, '8000', 'Oil change - drain tank - replace intake air filter'],
        ['air-dryer', 'Compressed-Air Dryer / Water Trap', 'air', null, 'days', null, null, 90, null, 'Replace desiccant - drain and clean filter bowl'],
        ['da-polisher', 'Dual-Action Polisher 15mm', 'polishing', 'Rupes', 'hours', '150', null, null, '1500', 'Backing plate + counterweight bearing inspection'],
        ['rotary-polisher', 'Rotary Polisher', 'polishing', 'Makita', 'hours', '250', null, null, '2000', 'Replace carbon brushes - re-grease gearbox'],
        ['nano-polisher', 'Mini / Nano Polisher', 'polishing', 'Rupes', 'hours', '120', null, null, '1000', 'Backing plate + battery health check'],
        ['orbital-sander', 'Random-Orbital Sander', 'polishing', 'Mirka', 'hours', '200', null, null, '1200', 'Pad brake + bearing service - clear dust extraction port'],
        ['thickness-gauge', 'Paint Thickness Gauge', 'measurement', 'DeFelsko', 'days', null, null, 365, null, 'Annual factory calibration certificate'],
        ['inspection-light', 'Paint Inspection Light', 'measurement', 'Scangrip', 'hours', '400', null, null, '3000', 'Battery capacity test - lens clean'],
        ['carpet-extractor', 'Hot-Water Carpet Extractor', 'interior', 'Mytee', 'hours', '200', null, null, '3000', 'Pump seal + heater element service (filter every 50 h)'],
        ['steam-cleaner', 'Steam Cleaner', 'interior', 'Dupray', 'hours', '100', null, null, '2500', 'Descale boiler - replace boiler gasket'],
        ['hepa-vacuum', 'Wet / Dry HEPA Vacuum', 'interior', 'Festool', 'hours', '40', null, null, '2000', 'Clean or replace HEPA filter (motor brushes at 500 h)'],
        ['ozone-generator', 'Ozone Generator', 'interior', null, 'hours', '100', null, null, '1500', 'Clean ozone plates (replace plate set at 1,500 h)'],
        ['tornador', 'Interior Cleaning Gun (Tornador)', 'interior', 'Tornador', 'cycles', null, 250, null, null, 'Clear nozzle - replace O-ring set'],
        ['curing-lamp', 'Infrared Curing Lamp', 'coating', null, 'hours', '1000', null, null, '2000', 'Lamp / emitter replacement - reflector clean'],
        ['heat-gun', 'Heat Gun (PPF / Tint)', 'coating', 'Steinel', 'hours', '300', null, null, '1200', 'Heating element + nozzle inspection'],
        ['vehicle-lift', 'Two-Post Vehicle Lift', 'facility', null, 'cycles', null, 250, null, null, 'Cable tension + lock engagement check (annual safety cert)'],
        ['bay-lighting', 'LED Bay Lighting Rig', 'facility', null, 'days', null, null, 180, '30000', 'Fixture clean - driver + wiring check'],
        ['detailing-cart', 'Detailing Cart & Bucket System', 'facility', null, 'days', null, null, 90, null, 'Caster / wheel check - grit guard clean'],
    ];

    /** package slug → [tool slug => hours one completed job puts on it] */
    private const array PROFILES = [
        'essential' => ['pressure-washer' => '0.750', 'car-dryer' => '0.500', 'hepa-vacuum' => '0.500'],
        'executive' => ['pressure-washer' => '0.750', 'car-dryer' => '0.500', 'hepa-vacuum' => '1.250', 'steam-cleaner' => '0.750'],
        'obsidian-elite' => ['pressure-washer' => '0.750', 'da-polisher' => '2.500', 'inspection-light' => '2.000', 'ozone-generator' => '0.500'],
        'paint-correction' => ['da-polisher' => '2.500', 'rotary-polisher' => '1.500', 'inspection-light' => '2.000'],
        'ceramic-coating' => ['da-polisher' => '2.500', 'curing-lamp' => '1.500', 'inspection-light' => '2.000'],
        'interior-rescue' => ['carpet-extractor' => '1.000', 'steam-cleaner' => '0.750', 'hepa-vacuum' => '1.250'],
    ];

    /**
     * @param  array<string, string>  $ids  source id → ULID, extended in place
     */
    public static function run(array &$ids): void
    {
        $owner = User::query()->findOrFail($ids['owner@mekanikomore.ph'] ?? throw new RuntimeException('EquipmentSeed needs the demo owner.'));
        $context = app(TenantContextResolver::class)->resolve($owner, null)->context ?? throw new RuntimeException('The demo owner has no tenant context.');

        app(TenantManager::class)->actingAs($context, function () use (&$ids): void {
            $branch = Branch::query()->findOrFail($ids['samahuzai-binan'] ?? throw new RuntimeException('EquipmentSeed needs the detailing branch.'));
            $manage = app(ManageEquipment::class);

            foreach (self::TOOLS as $index => [$slug, $name, $category, $brand, $meter, $hours, $cycles, $days, $life, $task]) {
                $ids["equipment:{$slug}"] = $manage->save($branch, null, array_filter([
                    'name' => $name,
                    'category' => $category,
                    'brand' => $brand,
                    'meter_type' => $meter,
                    'service_interval_hours' => $hours,
                    'service_interval_cycles' => $cycles,
                    'service_interval_days' => $days,
                    'expected_life_hours' => $life,
                    'maintenance_task' => $task,
                    'position' => ($index + 1) * 10,
                ], fn (mixed $value): bool => $value !== null))->id;
            }

            foreach (self::PROFILES as $package => $tools) {
                $lines = [];
                foreach ($tools as $slug => $hours) {
                    $lines[] = ['equipment_id' => $ids["equipment:{$slug}"], 'hours_per_job' => $hours];
                }
                $manage->setProfile(DetailingPackage::query()->findOrFail($ids["detailing:package:{$package}"] ?? throw new RuntimeException("EquipmentSeed needs package {$package}.")), $lines);
            }

            // The compressor arrived with hours on it, was serviced two months ago, and ran for a side job last week.
            $compressor = Equipment::query()->findOrFail($ids['equipment:air-compressor']);
            $manage->save($branch, $compressor, ['baseline_hours' => '420', 'purchase_cost_cents' => 8_500_000, 'purchase_date' => '2025-03-01']);
            $now = CarbonImmutable::now();
            $manage->logService($compressor, [
                'kind' => 'preventive',
                'performed_at' => $now->subDays(60)->toIso8601String(),
                'performed_by' => 'Rico Dimaculangan',
                'cost_cents' => 185_000,
                'downtime_hours' => '2.5',
                'parts' => 'Compressor oil, intake air filter',
            ]);
            $manage->logUsage($compressor, ['hours' => '6.5', 'cycles' => 0, 'used_at' => $now->subDays(7)->toIso8601String(), 'note' => 'Tyre shop next door borrowed the line']);
        });
    }
}

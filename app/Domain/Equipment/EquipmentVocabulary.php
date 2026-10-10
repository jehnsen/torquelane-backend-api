<?php

declare(strict_types=1);

namespace App\Domain\Equipment;

/**
 * The equipment register's lists and their labels, and the starting values a
 * category suggests, as the source had them (`equipmentUtils.js`). Served by
 * `GET /equipment/options` so no screen keeps a copy.
 */
final class EquipmentVocabulary
{
    public const array CATEGORIES = [
        'wash' => 'Wash & Decon',
        'polishing' => 'Polishing & Correction',
        'interior' => 'Interior & Steam',
        'coating' => 'Coating & Film',
        'air' => 'Compressed Air',
        'measurement' => 'Measurement & Light',
        'facility' => 'Facility & Support',
        'other' => 'Other',
    ];

    public const array STATUSES = [
        'available' => 'Available',
        'in_use' => 'In Use',
        'maintenance' => 'Under Maintenance',
        'repair' => 'Down / Repair',
        'retired' => 'Retired',
    ];

    public const array CONDITIONS = ['excellent' => 'Excellent', 'good' => 'Good', 'fair' => 'Fair', 'poor' => 'Poor'];

    public const array METER_TYPES = ['hours' => 'Runtime hours', 'cycles' => 'Job cycles', 'days' => 'Calendar days'];

    public const array MAINTENANCE_KINDS = [
        'preventive' => 'Preventive service',
        'repair' => 'Repair / breakdown',
        'inspection' => 'Inspection',
        'calibration' => 'Calibration',
        'part_replacement' => 'Part replacement',
    ];

    public const array SERVICE_LEVELS = ['ok' => 'OK', 'due_soon' => 'Due Soon', 'overdue' => 'Overdue'];

    public const array LIFE_LEVELS = ['healthy' => 'Healthy', 'aging' => 'Aging', 'replace_soon' => 'Replace Soon', 'end_of_life' => 'End of Life'];

    /** Sensible starting values per category, so nobody stares at blank interval boxes. */
    public const array CATEGORY_PRESETS = [
        'wash' => ['meter_type' => 'hours', 'service_interval_hours' => '200', 'expected_life_hours' => '2000', 'maintenance_task' => 'Inspect seals, filters, nozzles'],
        'polishing' => ['meter_type' => 'hours', 'service_interval_hours' => '180', 'expected_life_hours' => '1500', 'maintenance_task' => 'Backing plate + bearing / brush inspection'],
        'interior' => ['meter_type' => 'hours', 'service_interval_hours' => '100', 'expected_life_hours' => '2500', 'maintenance_task' => 'Clean / replace filters, descale'],
        'coating' => ['meter_type' => 'hours', 'service_interval_hours' => '500', 'expected_life_hours' => '1800', 'maintenance_task' => 'Emitter / element + nozzle inspection'],
        'air' => ['meter_type' => 'hours', 'service_interval_hours' => '500', 'expected_life_hours' => '8000', 'maintenance_task' => 'Oil change, drain tank, replace air filter'],
        'measurement' => ['meter_type' => 'days', 'service_interval_days' => 365, 'maintenance_task' => 'Annual factory calibration'],
        'facility' => ['meter_type' => 'days', 'service_interval_days' => 180, 'maintenance_task' => 'Safety + wear inspection'],
    ];

    /**
     * @param  array<string, string>  $labels
     * @return list<array{value: string, label: string}>
     */
    public static function options(array $labels): array
    {
        return array_map(fn (string $value, string $label): array => ['value' => $value, 'label' => $label], array_keys($labels), array_values($labels));
    }
}

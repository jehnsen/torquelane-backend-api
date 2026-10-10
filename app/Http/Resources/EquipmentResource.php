<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Equipment\EquipmentVocabulary;
use App\Domain\Shared\Calendar;
use App\Models\Equipment;
use App\Models\EquipmentMaintenanceLog;
use App\Models\EquipmentMaintenancePart;
use App\Models\EquipmentUsageLog;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A tool and its monitor report. Everything under `report` is derived by the
 * API when the tool is read (R2): no screen works out a meter, a ratio, a due
 * date or a score. Hours and ratios are numbers; money is integer centavos.
 * With `$withHistory`, the usage log and the service history ride along.
 *
 * @property Equipment $resource
 */
final class EquipmentResource extends JsonResource
{
    /**
     * @param  array<string, mixed>  $report  `EquipmentEngine::report`
     */
    public function __construct(Equipment $resource, private readonly array $report, private readonly bool $withHistory = false)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tool = $this->resource;
        $tool->loadMissing('bay');
        $report = $this->report;
        $service = is_array($report['service'] ?? null) ? $report['service'] : null;
        $life = is_array($report['life'] ?? null) ? $report['life'] : null;
        $forecast = is_array($report['forecast'] ?? null) ? $report['forecast'] : null;
        $reliability = is_array($report['reliability'] ?? null) ? $report['reliability'] : [];
        $iso = fn (mixed $ms): ?string => is_int($ms) ? CarbonImmutable::createFromTimestampMs($ms)->utc()->toIso8601ZuluString() : null;
        $serviceLevel = $service === null ? null : (is_string($service['level'] ?? null) ? $service['level'] : null);
        $lifeLevel = $life === null ? null : (is_string($life['level'] ?? null) ? $life['level'] : null);

        return [
            'id' => $tool->id,
            'branch_id' => $tool->branch_id,
            'name' => $tool->name,
            'category' => $tool->category,
            'category_label' => EquipmentVocabulary::CATEGORIES[$tool->category] ?? $tool->category,
            'brand' => $tool->brand,
            'model' => $tool->model,
            'serial_number' => $tool->serial_number,
            'bay' => $tool->bay === null ? null : ['id' => $tool->bay->id, 'name' => $tool->bay->name],
            'status' => $tool->status,
            'status_label' => EquipmentVocabulary::STATUSES[$tool->status] ?? $tool->status,
            'condition_rating' => $tool->condition_rating,
            'condition_label' => EquipmentVocabulary::CONDITIONS[$tool->condition_rating] ?? $tool->condition_rating,
            'purchase_date' => $tool->purchase_date?->toDateString(),
            'purchase_cost_cents' => $tool->purchase_cost_cents,
            'warranty_until' => $tool->warranty_until?->toDateString(),
            'expected_life_hours' => $tool->expected_life_hours === null ? null : (string) $tool->expected_life_hours,
            'meter_type' => $tool->meter_type,
            // What the count meter counts: `cups` for the espresso machine, else job `cycles`.
            'count_unit' => $tool->meter_type === 'cups' ? 'cups' : 'cycles',
            'baseline_hours' => (string) $tool->baseline_hours,
            'baseline_cycles' => $tool->baseline_cycles,
            'service_interval_hours' => $tool->service_interval_hours === null ? null : (string) $tool->service_interval_hours,
            'service_interval_cycles' => $tool->service_interval_cycles,
            'service_interval_days' => $tool->service_interval_days,
            'last_service_at' => $tool->last_service_at?->utc()->toIso8601ZuluString(),
            'maintenance_task' => $tool->maintenance_task,
            'notes' => $tool->notes,
            'is_active' => $tool->is_active,
            'position' => $tool->position,
            'report' => [
                'lifetime' => $report['lifetime'] ?? null,
                // Null = no interval set: the tool is not tracked. `binding` is the
                // limit that trips first; each trigger says the reading (`due_at`)
                // or the Manila date (`due_on`) it falls due at.
                'service' => $service === null ? null : ['triggers' => $this->triggers($service['triggers'] ?? null)] + $service + ['level_label' => EquipmentVocabulary::SERVICE_LEVELS[$serviceLevel ?? ''] ?? null],
                'forecast' => $forecast === null ? null : [
                    'days' => $forecast['days'] ?? null,
                    'due_at' => $iso($forecast['at_ms'] ?? null),
                    // The business date it falls on, Asia/Manila (R9).
                    'due_on' => is_int($forecast['at_ms'] ?? null) ? Calendar::toDate(CarbonImmutable::createFromTimestampMs($forecast['at_ms'])) : null,
                    'rate_per_day' => $forecast['rate'] ?? null,
                ],
                'life' => $life === null ? null : $life + ['level_label' => EquipmentVocabulary::LIFE_LEVELS[$lifeLevel ?? ''] ?? null],
                'reliability' => array_diff_key($reliability, ['last_serviced_at_ms' => true]) + ['last_serviced_at' => $iso($reliability['last_serviced_at_ms'] ?? null)],
                'utilization' => $report['utilization'] ?? null,
                'health' => $report['health'] ?? null,
            ],
            'usage_logs' => $this->withHistory ? array_values($tool->usageLogs()->limit(200)->get()->map(fn (EquipmentUsageLog $log): array => [
                'id' => $log->id,
                'hours' => (string) $log->hours,
                'cycles' => $log->cycles,
                'used_at' => $log->used_at->utc()->toIso8601ZuluString(),
                'detailing_booking_id' => $log->detailing_booking_id,
                'note' => $log->note,
                'logged_by_name' => $log->logged_by_name,
            ])->all()) : null,
            'maintenance_logs' => $this->withHistory ? array_values($tool->maintenanceLogs()->with('issuedParts.item')->limit(200)->get()->map(fn (EquipmentMaintenanceLog $log): array => [
                'id' => $log->id,
                'kind' => $log->kind,
                'kind_label' => EquipmentVocabulary::MAINTENANCE_KINDS[$log->kind] ?? $log->kind,
                'performed_at' => $log->performed_at->utc()->toIso8601ZuluString(),
                'performed_by' => $log->performed_by,
                'cost_cents' => $log->cost_cents,
                // What the parts taken from stock were worth, and the entry in all.
                'parts_cost_cents' => $log->parts_cost_cents,
                'total_cost_cents' => $log->totalCostCents(),
                'issued_parts' => array_values($log->issuedParts->map(fn (EquipmentMaintenancePart $part): array => [
                    'item_id' => $part->item_id,
                    'sku' => $part->item->sku,
                    'name' => $part->item->name,
                    'uom' => $part->item->uom,
                    'quantity' => (string) $part->quantity,
                    'value_cents' => $part->value_cents,
                ])->all()),
                'downtime_hours' => (string) $log->downtime_hours,
                'parts' => $log->parts,
                'notes' => $log->notes,
                'meter_hours' => $log->meter_hours === null ? null : (string) $log->meter_hours,
                'meter_cycles' => $log->meter_cycles,
                'resets_service' => $log->resets_service,
                'recorded_by_name' => $log->recorded_by_name,
            ])->all()) : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function triggers(mixed $triggers): array
    {
        $rows = [];
        foreach (is_array($triggers) ? $triggers : [] as $trigger) {
            if (! is_array($trigger)) {
                continue;
            }
            $dueOn = $trigger['due_on_ms'] ?? null;
            $rows[] = [
                'key' => $trigger['key'] ?? null,
                'unit' => $trigger['unit'] ?? null,
                'used' => $trigger['used'] ?? null,
                'interval' => $trigger['interval'] ?? null,
                'ratio' => $trigger['ratio'] ?? null,
                'remaining' => $trigger['remaining'] ?? null,
                // The reading a meter limit falls due at; the Manila date (R9) a calendar limit does.
                'due_at' => $trigger['due_at'] ?? null,
                'due_on' => is_int($dueOn) ? Calendar::toDate(CarbonImmutable::createFromTimestampMs($dueOn)) : null,
            ];
        }

        return $rows;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Actions\WorkOrders\WorkOrderView;
use App\Domain\Analytics\DemandBand;
use App\Domain\Analytics\MonthlyCostPoint;
use App\Domain\Analytics\RollingSpend;
use App\Domain\Analytics\ServiceDemand;
use App\Domain\Analytics\UpcomingBucket;
use App\Domain\Fleet\PmsItem;
use App\Domain\Shop\NamedValue;
use App\Models\ServiceTask;
use App\Models\Vehicle;
use Illuminate\Http\Request;

/**
 * The JSON shapes the analytics and shop read endpoints share: money in
 * integer centavos, dates as Manila business dates, snake_case.
 */
final class AnalyticsJson
{
    /**
     * @param  list<MonthlyCostPoint>  $points
     * @return list<array<string, int|string>>
     */
    public static function monthly(array $points): array
    {
        return array_map(fn (MonthlyCostPoint $p): array => [
            'key' => $p->key,
            'month' => $p->month,
            'parts_cents' => $p->partsCents,
            'labor_cents' => $p->laborCents,
            'total_cents' => $p->totalCents,
            'preventive' => $p->preventive,
            'corrective' => $p->corrective,
        ], $points);
    }

    /**
     * @param  list<UpcomingBucket>  $buckets
     * @return list<array<string, int|string>>
     */
    public static function upcoming(array $buckets): array
    {
        return array_map(fn (UpcomingBucket $b): array => [
            'label' => $b->label,
            'range' => $b->range,
            'overdue' => $b->overdue,
            'due_soon' => $b->dueSoon,
            'upcoming' => $b->upcoming,
        ], $buckets);
    }

    /**
     * @return array<string, array<string, int>>
     */
    public static function demand(ServiceDemand $demand): array
    {
        $band = fn (DemandBand $b): array => ['count' => $b->count, 'vehicle_count' => $b->vehicleCount, 'estimated_cost_cents' => $b->estimatedCostCents];

        return ['overdue' => $band($demand->overdue), 'due_soon' => $band($demand->dueSoon)];
    }

    /**
     * @return array<string, int>
     */
    public static function spend(RollingSpend $spend): array
    {
        return [
            'window_days' => $spend->windowDays,
            'current_cents' => $spend->currentCents,
            'previous_cents' => $spend->previousCents,
            'delta_pct' => $spend->deltaPct,
        ];
    }

    /**
     * @return array<string, string|null>
     */
    public static function vehicle(?Vehicle $vehicle): ?array
    {
        return $vehicle === null ? null : [
            'id' => $vehicle->id,
            'plate_number' => $vehicle->plate_number,
            'make' => $vehicle->make,
            'model' => $vehicle->model,
            'customer_account_id' => $vehicle->customer_account_id,
        ];
    }

    /**
     * A PMS item with its vehicle and its catalogue cost.
     *
     * @param  array<string, ServiceTask>  $tasks
     * @param  array<string, int>  $costs  task id → estimated cost in centavos
     * @return array<string, mixed>
     */
    public static function item(Request $request, ?Vehicle $vehicle, PmsItem $item, array $tasks, array $costs): array
    {
        return [
            'vehicle' => self::vehicle($vehicle),
            'item' => (new PmsItemResource($item, $tasks))->resolve($request) + ['estimated_cost_cents' => $costs[$item->task->id] ?? 0],
        ];
    }

    /**
     * @param  list<NamedValue>  $rows
     * @return list<array<string, float|int|string|null>>
     */
    public static function named(array $rows, bool $money): array
    {
        return array_map(fn (NamedValue $row): array => [
            'name' => $row->name,
            $money ? 'value_cents' : 'value' => $money ? (int) $row->value : $row->value,
            'meta' => $row->meta,
        ], $rows);
    }

    /**
     * A work order as the lists render it, with its vehicle's label.
     *
     * @param  array<string, Vehicle>  $vehicles
     * @return array<string, mixed>
     */
    public static function workOrder(Request $request, WorkOrderView $view, array $vehicles): array
    {
        $resolved = [];
        foreach ((new WorkOrderResource($view))->resolve($request) as $key => $value) {
            $resolved[(string) $key] = $value;
        }

        return $resolved + ['vehicle' => self::vehicle($vehicles[$view->order->vehicle_id] ?? null)];
    }
}

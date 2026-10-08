<?php

declare(strict_types=1);

namespace App\Domain\Parts;

use App\Domain\Fleet\VehicleHealth;
use App\Domain\Shared\Calendar;
use App\Domain\WorkOrders\WorkOrderStatus;
use DateTimeImmutable;

/**
 * Port of ../web/lib/parts-forecast.ts (golden-tested against
 * parts-forecast.json): parts demand over a horizon, projected from the PMS
 * engine's own due dates (nothing re-derived here).
 *
 * A due item already covered by a live work order or an open purchase order
 * is excluded, so re-running the forecast — or running it after raising a
 * job or a purchase request — never double-counts. Money is in centavos.
 */
final class PartsForecast
{
    /**
     * @param  list<VehicleHealth>  $health  in display order (ties keep it)
     * @param  list<WorkCoverage>  $workOrders
     * @param  list<PurchaseCoverage>  $purchaseOrders
     * @param  list<FleetPartFacts>  $parts  the account's own parts
     * @param  list<PartUsage>  $usages  which parts each task consumes
     * @return list<PartDemandRow> largest shortfall first, then largest quantity
     */
    public static function demand(array $health, array $workOrders, array $purchaseOrders, array $parts, array $usages, int $horizonWeeks, DateTimeImmutable $today): array
    {
        $horizonEnd = Calendar::addDays($today, 7 * $horizonWeeks);

        $covered = [];
        foreach ($workOrders as $order) {
            if (in_array($order->status, [WorkOrderStatus::Closed, WorkOrderStatus::Cancelled, WorkOrderStatus::Declined], true)) {
                continue;
            }
            foreach ($order->taskIds as $taskId) {
                $covered[self::key($order->vehicleId, $taskId)] = true;
            }
        }
        foreach ($purchaseOrders as $po) {
            if (! $po->status->isOpen()) {
                continue;
            }
            foreach ($po->lines as $line) {
                foreach ($line['taskIds'] as $taskId) {
                    foreach ($line['vehicleIds'] as $vehicleId) {
                        $covered[self::key($vehicleId, $taskId)] = true;
                    }
                }
            }
        }

        /** @var array<string, list<DemandContributor>> $contributors part id → items, in first-met order */
        $contributors = [];
        foreach ($health as $entry) {
            foreach ($entry->items as $item) {
                if (Calendar::parseDate($item->dueDate) > $horizonEnd) {
                    continue;
                }
                if (isset($covered[self::key($entry->vehicle->id, $item->task->id)])) {
                    continue;
                }
                foreach ($usages as $usage) {
                    if ($usage->serviceTaskId === $item->task->id) {
                        $contributors[$usage->partId][] = new DemandContributor($entry->vehicle->id, $item->task->id, $item->dueDate);
                    }
                }
            }
        }

        $partsById = [];
        foreach ($parts as $part) {
            $partsById[$part->id] = $part;
        }

        $rows = [];
        foreach ($contributors as $partId => $items) {
            $partId = (string) $partId;
            $part = $partsById[$partId] ?? null;
            if ($part === null) {
                continue;
            }

            $perTask = [];
            foreach ($usages as $usage) {
                if ($usage->partId === $partId) {
                    $perTask[$usage->serviceTaskId] = $usage->quantityPerService;
                }
            }

            $required = 0;
            $earliest = $items[0]->dueDate;
            foreach ($items as $item) {
                $required += $perTask[$item->taskId] ?? 0;
                if ($item->dueDate < $earliest) {
                    $earliest = $item->dueDate;
                }
            }

            $shortfall = max(0, $required - $part->currentStock);
            $rows[] = new PartDemandRow(
                $part,
                $required,
                $shortfall,
                $shortfall * $part->unitCostCents,
                $earliest,
                $shortfall > 0 && Calendar::differenceInCalendarDays(Calendar::parseDate($earliest), $today) <= $part->leadTimeDays,
                $items,
            );
        }

        usort($rows, fn (PartDemandRow $a, PartDemandRow $b): int => ($b->shortfall <=> $a->shortfall) ?: ($b->quantityRequired <=> $a->quantityRequired));

        return $rows;
    }

    /**
     * The one-line, plain-English reading of the table: what a purchasing
     * officer reads first (../web `summariseDemand`).
     *
     * @param  list<PartDemandRow>  $rows
     */
    public static function summarise(array $rows, int $horizonWeeks): string
    {
        $active = array_values(array_filter($rows, fn (PartDemandRow $row): bool => $row->quantityRequired > 0));
        if ($active === []) {
            return "Next {$horizonWeeks} weeks — nothing due that isn't already covered.";
        }

        $byQuantity = $active;
        usort($byQuantity, fn (PartDemandRow $a, PartDemandRow $b): int => $b->quantityRequired <=> $a->quantityRequired);
        $headline = implode(', ', array_map(self::displayQuantity(...), array_slice($byQuantity, 0, 3)));

        $byShortfall = $active;
        usort($byShortfall, fn (PartDemandRow $a, PartDemandRow $b): int => $b->shortfall <=> $a->shortfall);
        $constrained = $byShortfall[0];
        $coverage = $constrained->shortfall > 0
            ? sprintf(
                ' Current stock covers %d of %d %s%s.',
                min($constrained->part->currentStock, $constrained->quantityRequired),
                $constrained->quantityRequired,
                mb_strtolower(self::bareName($constrained->part->name)),
                $constrained->part->unit === 'piece' ? 's' : '',
            )
            : '';

        return "Next {$horizonWeeks} weeks — {$headline}.{$coverage}";
    }

    private static function displayQuantity(PartDemandRow $row): string
    {
        $bare = self::bareName($row->part->name);
        if ($row->part->unit === 'litre') {
            return "{$row->quantityRequired} L {$bare}";
        }
        $plural = $row->part->unit === 'piece' && $row->quantityRequired !== 1 ? "{$bare}s" : $bare;

        return $row->quantityRequired.' '.mb_strtolower($plural);
    }

    /** The name without a trailing parenthetical: "DOT 4 brake fluid (litre)" → "DOT 4 brake fluid". */
    private static function bareName(string $name): string
    {
        return (string) preg_replace('/\s*\([^)]*\)\s*$/', '', $name);
    }

    private static function key(string $vehicleId, string $taskId): string
    {
        return $vehicleId.':'.$taskId;
    }
}

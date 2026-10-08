<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Fleet\ServiceTaskFacts;
use App\Domain\Fleet\VehicleHealth;
use App\Domain\Shared\Calendar;
use App\Domain\Shared\JsMath;
use App\Domain\Shop\NamedValue;
use DateTimeImmutable;

/**
 * Port of ../web/lib/analytics.ts (golden-tested against analytics.json): the
 * series the fleet dashboard, schedule and reports read. Money in centavos,
 * summed exactly; day and month keys are Asia/Manila dates (R9).
 */
final class Analytics
{
    public const string UNSCHEDULED = 'Unscheduled repairs';

    /**
     * Closed spend per month, oldest first, empty months kept in place. Parts
     * are the estimate's aggregate (`partsCost`); the total is workOrderCost.
     *
     * @param  list<AnalyticsOrder>  $orders
     * @return list<MonthlyCostPoint>
     */
    public static function monthlyCosts(array $orders, int $months, DateTimeImmutable $today): array
    {
        /** @var array<string, array{month: string, parts: int, labor: int, total: int, preventive: int, corrective: int}> $buckets */
        $buckets = [];
        $start = Calendar::local($today)->modify('first day of this month')->setTime(0, 0);
        for ($i = $months - 1; $i >= 0; $i--) {
            $date = Calendar::addMonths($start, -$i);
            $buckets[$date->format('Y-m')] = ['month' => $date->format('M'), 'parts' => 0, 'labor' => 0, 'total' => 0, 'preventive' => 0, 'corrective' => 0];
        }

        foreach ($orders as $order) {
            if (! $order->isClosed() || $order->completedOn === null) {
                continue;
            }
            $key = substr($order->completedOn, 0, 7);
            if (! isset($buckets[$key])) {
                continue;
            }
            $buckets[$key]['parts'] += $order->partsCents;
            $buckets[$key]['labor'] += $order->laborCents;
            $buckets[$key]['total'] += $order->costCents;
            $order->type === 'corrective' ? $buckets[$key]['corrective']++ : $buckets[$key]['preventive']++;
        }

        $points = [];
        foreach ($buckets as $key => $b) {
            $points[] = new MonthlyCostPoint((string) $key, $b['month'], $b['parts'], $b['labor'], $b['total'], $b['preventive'], $b['corrective']);
        }

        return $points;
    }

    /**
     * Closed spend per vehicle, highest first (ties keep vehicle order).
     *
     * @param  list<AnalyticsOrder>  $orders
     * @param  list<AnalyticsVehicle>  $vehicles  in health order
     * @return list<NamedValue> value in centavos, meta "make model"
     */
    public static function spendByVehicle(array $orders, array $vehicles, int $limit = 8): array
    {
        $byVehicle = [];
        foreach ($orders as $order) {
            if ($order->isClosed()) {
                $byVehicle[$order->vehicleId] = ($byVehicle[$order->vehicleId] ?? 0) + $order->costCents;
            }
        }

        $rows = array_map(fn (AnalyticsVehicle $v): NamedValue => new NamedValue($v->plateNumber, $byVehicle[$v->id] ?? 0, "{$v->make} {$v->model}"), $vehicles);

        return self::top($rows, $limit);
    }

    /**
     * Closed spend by the order's service item: the first catalogue task the
     * order discharges, else "Unscheduled repairs". Highest first.
     *
     * @param  list<AnalyticsOrder>  $orders
     * @param  list<ServiceTaskFacts>  $catalogue  in catalogue order
     * @return list<NamedValue> value in centavos
     */
    public static function spendByCategory(array $orders, array $catalogue): array
    {
        $totals = [];
        foreach ($orders as $order) {
            if (! $order->isClosed()) {
                continue;
            }
            $label = self::UNSCHEDULED;
            foreach ($catalogue as $task) {
                if (in_array($task->id, $order->taskIds, true)) {
                    $label = $task->name;
                    break;
                }
            }
            $totals[$label] = ($totals[$label] ?? 0) + $order->costCents;
        }

        $rows = [];
        foreach ($totals as $name => $value) {
            $rows[] = new NamedValue((string) $name, $value);
        }

        return self::top($rows, null);
    }

    /**
     * The forward workload: PMS items landing in each of the next `weeks`
     * weeks. Overdue items collapse into the first bucket: work needed now.
     *
     * @param  list<VehicleHealth>  $health
     * @return list<UpcomingBucket>
     */
    public static function upcomingLoad(array $health, int $weeks, DateTimeImmutable $today): array
    {
        $overdue = array_fill(0, max($weeks, 1), 0);
        $dueSoon = $overdue;
        $upcoming = $overdue;

        foreach ($health as $entry) {
            foreach ($entry->items as $item) {
                if ($item->status === 'overdue') {
                    $overdue[0]++;

                    continue;
                }
                $days = Calendar::differenceInCalendarDays(Calendar::parseDate($item->dueDate), $today);
                $index = (int) floor($days / 7);
                if ($index < 0 || $index >= $weeks) {
                    continue;
                }
                if ($item->status === 'due_soon') {
                    $dueSoon[$index]++;
                } else {
                    $upcoming[$index]++;
                }
            }
        }

        $buckets = [];
        for ($index = 0; $index < $weeks; $index++) {
            $buckets[] = new UpcomingBucket(
                $index === 0 ? 'This week' : 'Week '.($index + 1),
                Calendar::addDays($today, 7 * $index)->format('d M').' – '.Calendar::addDays($today, 7 * ($index + 1))->format('d M'),
                $overdue[$index],
                $dueSoon[$index],
                $upcoming[$index],
            );
        }

        return $buckets;
    }

    /**
     * Every non-compliant PMS item, most urgent first (fewest days left;
     * ties keep fleet order).
     *
     * @param  list<VehicleHealth>  $health
     * @return list<UrgentItem>
     */
    public static function urgentItems(array $health): array
    {
        $items = [];
        foreach ($health as $entry) {
            foreach ($entry->items as $item) {
                if ($item->status !== 'ok') {
                    $items[] = new UrgentItem($entry->vehicle, $item);
                }
            }
        }
        usort($items, fn (UrgentItem $a, UrgentItem $b): int => $a->item->daysRemaining <=> $b->item->daysRemaining);

        return $items;
    }

    /**
     * The single source for "how much work is outstanding": the dashboard
     * and the schedule read counts and costs from here.
     *
     * @param  list<VehicleHealth>  $health
     * @param  array<string, int>  $taskCostCents  catalogue estimated cost by task id
     */
    public static function serviceDemand(array $health, array $taskCostCents): ServiceDemand
    {
        $urgent = self::urgentItems($health);
        $band = function (string $status) use ($urgent, $taskCostCents): DemandBand {
            $items = array_values(array_filter($urgent, fn (UrgentItem $u): bool => $u->item->status === $status));
            $vehicles = [];
            $cost = 0;
            foreach ($items as $u) {
                $vehicles[$u->vehicle->id] = true;
                $cost += $taskCostCents[$u->item->task->id] ?? 0;
            }

            return new DemandBand($items, count($items), count($vehicles), $cost);
        };

        return new ServiceDemand($band('overdue'), $band('due_soon'));
    }

    /**
     * Spend over two equal trailing windows (calendar months mid-month are
     * not comparable). A completion date counts from its local midnight.
     *
     * @param  list<AnalyticsOrder>  $orders
     */
    public static function rollingSpend(array $orders, int $windowDays, DateTimeImmutable $now): RollingSpend
    {
        $currentStart = Calendar::addDays($now, -$windowDays);
        $previousStart = Calendar::addDays($now, -2 * $windowDays);

        $current = 0;
        $previous = 0;
        foreach ($orders as $order) {
            if (! $order->isClosed() || $order->completedOn === null) {
                continue;
            }
            $completed = Calendar::parseDate($order->completedOn);
            if ($completed > $currentStart && $completed <= $now) {
                $current += $order->costCents;
            } elseif ($completed > $previousStart && $completed <= $currentStart) {
                $previous += $order->costCents;
            }
        }

        $delta = $previous !== 0 ? (int) JsMath::round(($current - $previous) / $previous * 100) : 0;

        return new RollingSpend($current, $previous, $delta, $windowDays);
    }

    /**
     * Distance the fleet covers in a period, from each vehicle's daily
     * average: cost per km needs the distance driven DURING the period.
     *
     * @param  list<AnalyticsVehicle>  $vehicles
     */
    public static function fleetKmInPeriod(array $vehicles, int $days): int
    {
        $total = 0;
        foreach ($vehicles as $vehicle) {
            $total += $vehicle->avgDailyKm * $days;
        }

        return (int) JsMath::round($total);
    }

    /**
     * Completed services per 10,000 km for each vehicle, highest first:
     * normalising by distance keeps hard-worked units from looking worse.
     *
     * @param  list<AnalyticsOrder>  $orders
     * @param  list<AnalyticsVehicle>  $vehicles  in health order
     * @return list<NamedValue> value to two decimals, meta "N services · Nk km"
     */
    public static function serviceFrequency(array $orders, array $vehicles, int $limit = 8): array
    {
        $counts = [];
        foreach ($orders as $order) {
            if ($order->isClosed()) {
                $counts[$order->vehicleId] = ($counts[$order->vehicleId] ?? 0) + 1;
            }
        }

        $rows = array_map(function (AnalyticsVehicle $v) use ($counts): NamedValue {
            $services = $counts[$v->id] ?? 0;
            $per10k = $v->odometer > 0 ? ($services / $v->odometer) * 10000 : 0;

            return new NamedValue(
                $v->plateNumber,
                JsMath::round($per10k * 100) / 100,
                $services.' services · '.JsMath::toString(JsMath::round($v->odometer / 1000)).'k km',
            );
        }, $vehicles);

        return self::top($rows, $limit);
    }

    /**
     * Mean days between completed services across the fleet, over a window
     * of `months` × 30.44 days.
     *
     * @param  list<AnalyticsOrder>  $orders
     */
    public static function meanDaysBetweenServices(array $orders, int $vehicleCount, int $months = 12): int
    {
        $completed = count(array_filter($orders, fn (AnalyticsOrder $o): bool => $o->isClosed()));
        if ($completed === 0 || $vehicleCount === 0) {
            return 0;
        }

        $windowDays = $months * 30.44;
        $perVehicle = $completed / $vehicleCount;

        return (int) JsMath::round($windowDays / max($perVehicle, 0.01));
    }

    /**
     * Highest value first, ties in input order, optionally the first `$limit`.
     *
     * @param  list<NamedValue>  $rows
     * @return list<NamedValue>
     */
    private static function top(array $rows, ?int $limit): array
    {
        usort($rows, fn (NamedValue $a, NamedValue $b): int => $b->value <=> $a->value);

        return $limit === null ? $rows : array_slice($rows, 0, $limit);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Golden\Support;

use App\Domain\Analytics\Analytics;
use App\Domain\Analytics\AnalyticsOrder;
use App\Domain\Analytics\AnalyticsVehicle;
use App\Domain\Analytics\DemandBand;
use App\Domain\Analytics\MonthlyCostPoint;
use App\Domain\Analytics\UpcomingBucket;
use App\Domain\Analytics\UrgentItem;
use App\Domain\Fleet\ServiceTaskFacts;
use App\Domain\Parts\DemandContributor;
use App\Domain\Parts\FleetPartFacts;
use App\Domain\Parts\PartDemandRow;
use App\Domain\Parts\PartsForecast;
use App\Domain\Parts\PartUsage;
use App\Domain\Parts\PurchaseCoverage;
use App\Domain\Parts\WorkCoverage;
use App\Domain\PurchaseOrders\PurchaseOrderStatus;
use App\Domain\Shop\NamedValue;
use App\Domain\WorkOrders\WorkOrderStatus;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use LogicException;

/**
 * Calls the Phase 4 ports (App\Domain\Parts\PartsForecast,
 * App\Domain\Analytics\Analytics) with a fixture's frontend-shaped arguments
 * and returns results in the fixture's shape. Records the frontend passed
 * whole (a part, a vehicle, a PMS item) are echoed back as given, so the
 * comparison covers what the port computes and nothing else. Money comes out
 * as `['cents' => n]`, compared in integer centavos.
 *
 * An argument the TypeScript defaulted (`new Date()`, a limit) takes the
 * frozen clock or the TypeScript default.
 */
final class PartsAnalyticsPort
{
    /** module → functions replayed */
    public const array REPLAYED = [
        'parts-forecast' => ['computePartsDemand', 'summariseDemand'],
        'analytics' => ['monthlyCosts', 'spendByVehicle', 'spendByCategory', 'upcomingLoad', 'urgentItems', 'serviceDemand', 'rollingSpend', 'fleetKmInPeriod', 'serviceFrequency', 'meanDaysBetweenServices'],
    ];

    /**
     * @param  list<mixed>  $input  resolved with money kept (WebMoney)
     */
    public static function call(string $fn, array $input): mixed
    {
        return match ($fn) {
            'computePartsDemand' => self::demand($input),
            'summariseDemand' => PartsForecast::summarise(array_map(self::rowFrom(...), self::list($input[0])), self::int($input[1])),
            'monthlyCosts' => array_map(fn (MonthlyCostPoint $p): array => [
                'key' => $p->key,
                'month' => $p->month,
                'parts' => ['cents' => $p->partsCents],
                'labor' => ['cents' => $p->laborCents],
                'total' => ['cents' => $p->totalCents],
                'preventive' => $p->preventive,
                'corrective' => $p->corrective,
            ], Analytics::monthlyCosts(self::orders($input[0]), self::int($input[1] ?? 12), self::today($input[2] ?? null))),
            'spendByVehicle' => self::named(Analytics::spendByVehicle(self::orders($input[0]), self::vehicles($input[1]), self::int($input[2] ?? 8)), true),
            'spendByCategory' => self::named(Analytics::spendByCategory(self::orders($input[0]), self::catalogue()), true),
            'upcomingLoad' => array_map(fn (UpcomingBucket $b): array => [
                'label' => $b->label,
                'range' => $b->range,
                'overdue' => $b->overdue,
                'dueSoon' => $b->dueSoon,
                'upcoming' => $b->upcoming,
            ], Analytics::upcomingLoad(self::health($input[0])->health, self::int($input[1] ?? 6), self::today($input[2] ?? null))),
            'urgentItems' => self::urgent($input[0]),
            'serviceDemand' => self::serviceDemand($input[0]),
            'rollingSpend' => (function () use ($input): array {
                $s = Analytics::rollingSpend(self::orders($input[0]), self::int($input[1] ?? 30), self::today($input[2] ?? null));

                return ['current' => ['cents' => $s->currentCents], 'previous' => ['cents' => $s->previousCents], 'deltaPct' => $s->deltaPct, 'windowDays' => $s->windowDays];
            })(),
            'fleetKmInPeriod' => Analytics::fleetKmInPeriod(array_map(self::vehicle(...), self::list($input[0])), self::int($input[1])),
            'serviceFrequency' => self::named(Analytics::serviceFrequency(self::orders($input[0]), self::vehicles($input[1]), self::int($input[2] ?? 8)), false),
            'meanDaysBetweenServices' => Analytics::meanDaysBetweenServices(self::orders($input[0]), self::int($input[1]), self::int($input[2] ?? 12)),
            default => throw new LogicException("{$fn} is not replayed."),
        };
    }

    /**
     * The usages the forecast ran with: parts.json's SERVICE_ITEM_PARTS.
     *
     * @return list<PartUsage>
     */
    public static function usages(): array
    {
        $constants = self::map(collect(WebFixtures::raw('parts'))->firstWhere('fn', '$constants')['output'] ?? null);

        return array_map(function (mixed $link): PartUsage {
            $l = self::map($link);

            return new PartUsage(self::str($l['serviceTaskId'] ?? null), self::str($l['partId'] ?? null), self::int($l['quantityPerService'] ?? null));
        }, self::list($constants['SERVICE_ITEM_PARTS'] ?? null));
    }

    // --------------------------------------------------------------- parts

    /**
     * @param  list<mixed>  $input  health, workOrders, purchaseOrders, parts, horizonWeeks, today
     * @return list<array<string, mixed>>
     */
    private static function demand(array $input): array
    {
        $partsIn = self::list($input[3]);
        $records = [];
        foreach ($partsIn as $part) {
            $records[self::str(self::map($part)['id'] ?? null)] = $part;
        }

        $rows = PartsForecast::demand(
            self::health($input[0])->health,
            array_map(function (mixed $order): WorkCoverage {
                $o = self::map($order);

                return new WorkCoverage(WorkOrderStatus::from(self::str($o['status'] ?? null)), self::str($o['vehicleId'] ?? null), self::strings($o['taskIds'] ?? []));
            }, self::list($input[1])),
            array_map(function (mixed $po): PurchaseCoverage {
                $p = self::map($po);

                return new PurchaseCoverage(PurchaseOrderStatus::from(self::str($p['status'] ?? null)), array_map(function (mixed $line): array {
                    $l = self::map($line);

                    return ['taskIds' => self::strings($l['serviceTaskIds'] ?? []), 'vehicleIds' => self::strings($l['vehicleIds'] ?? [])];
                }, self::list($p['lines'] ?? [])));
            }, self::list($input[2])),
            array_map(self::part(...), $partsIn),
            self::usages(),
            self::int($input[4]),
            self::today($input[5] ?? null),
        );

        return array_map(fn (PartDemandRow $row): array => [
            'part' => $records[$row->part->id],
            'quantityRequired' => $row->quantityRequired,
            'shortfall' => $row->shortfall,
            'estimatedCost' => ['cents' => $row->estimatedCostCents],
            'earliestNeededOn' => $row->earliestNeededOn,
            'leadTimeRisk' => $row->leadTimeRisk,
            'contributingItems' => array_map(fn (DemandContributor $c): array => ['vehicleId' => $c->vehicleId, 'taskId' => $c->taskId, 'dueDate' => $c->dueDate], $row->contributingItems),
        ], $rows);
    }

    private static function part(mixed $part): FleetPartFacts
    {
        $p = self::map($part);

        return new FleetPartFacts(
            self::str($p['id'] ?? null),
            self::str($p['sku'] ?? null),
            self::str($p['name'] ?? null),
            self::str($p['category'] ?? null),
            self::str($p['unit'] ?? null),
            self::cents($p['unitCost'] ?? null),
            self::int($p['currentStock'] ?? null),
            self::int($p['reorderPoint'] ?? null),
            self::str($p['preferredVendor'] ?? null),
            self::int($p['leadTimeDays'] ?? null),
        );
    }

    private static function rowFrom(mixed $row): PartDemandRow
    {
        $r = self::map($row);

        return new PartDemandRow(
            self::part($r['part'] ?? null),
            self::int($r['quantityRequired'] ?? null),
            self::int($r['shortfall'] ?? null),
            self::cents($r['estimatedCost'] ?? null),
            self::str($r['earliestNeededOn'] ?? null),
            (bool) ($r['leadTimeRisk'] ?? false),
            array_map(function (mixed $c): DemandContributor {
                $c = self::map($c);

                return new DemandContributor(self::str($c['vehicleId'] ?? null), self::str($c['taskId'] ?? null), self::str($c['dueDate'] ?? null));
            }, self::list($r['contributingItems'] ?? [])),
        );
    }

    // ----------------------------------------------------------- analytics

    /**
     * Fixture health → domain health, remembering which fixture record each
     * vehicle and item came from, so outputs can echo them.
     */
    private static function health(mixed $health): HealthIndex
    {
        $index = new HealthIndex;
        foreach (self::list($health) as $entry) {
            $e = self::map($entry);
            $domain = FleetPort::healthFrom($e);
            $index->health[] = $domain;
            $index->vehicles[$domain->vehicle] = $e['vehicle'] ?? null;
            foreach (self::list($e['items'] ?? []) as $i => $item) {
                $index->items[$domain->items[$i]] = $item;
                $task = self::map(self::map($item)['task'] ?? null);
                $index->taskCostCents[self::str($task['id'] ?? null)] = self::cents($task['estimatedCost'] ?? 0);
            }
        }

        return $index;
    }

    /**
     * @return list<array{vehicle: mixed, item: mixed}>
     */
    private static function urgent(mixed $health): array
    {
        $index = self::health($health);

        return self::echoItems($index, Analytics::urgentItems($index->health));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function serviceDemand(mixed $health): array
    {
        $index = self::health($health);
        $demand = Analytics::serviceDemand($index->health, $index->taskCostCents);
        $band = fn (DemandBand $b): array => [
            'items' => self::echoItems($index, $b->items),
            'count' => $b->count,
            'vehicleCount' => $b->vehicleCount,
            'estimatedCost' => ['cents' => $b->estimatedCostCents],
        ];

        return ['overdue' => $band($demand->overdue), 'dueSoon' => $band($demand->dueSoon)];
    }

    /**
     * @param  list<UrgentItem>  $items
     * @return list<array{vehicle: mixed, item: mixed}>
     */
    private static function echoItems(HealthIndex $index, array $items): array
    {
        return array_map(fn (UrgentItem $u): array => ['vehicle' => $index->vehicles[$u->vehicle], 'item' => $index->items[$u->item]], $items);
    }

    /**
     * @return list<AnalyticsOrder>
     */
    private static function orders(mixed $orders): array
    {
        return array_map(function (mixed $order): AnalyticsOrder {
            $o = self::map($order);

            return new AnalyticsOrder(
                self::str($o['vehicleId'] ?? null),
                WorkOrderStatus::from(self::str($o['status'] ?? null)),
                self::str($o['type'] ?? null),
                is_string($o['completedOn'] ?? null) ? $o['completedOn'] : null,
                self::cents($o['partsCost'] ?? 0),
                self::cents($o['laborCost'] ?? 0),
                FleetPort::costOf($o, true),
                self::strings($o['taskIds'] ?? []),
            );
        }, self::list($orders));
    }

    /**
     * The vehicles of a health list, in its order.
     *
     * @return list<AnalyticsVehicle>
     */
    private static function vehicles(mixed $health): array
    {
        return array_map(fn (mixed $entry): AnalyticsVehicle => self::vehicle(self::map($entry)['vehicle'] ?? null), self::list($health));
    }

    private static function vehicle(mixed $vehicle): AnalyticsVehicle
    {
        $v = self::map($vehicle);

        return new AnalyticsVehicle(
            self::str($v['id'] ?? null),
            self::str($v['plateNumber'] ?? ''),
            self::str($v['make'] ?? ''),
            self::str($v['model'] ?? ''),
            self::num($v['odometer'] ?? 0),
            self::num($v['avgDailyKm'] ?? 0),
        );
    }

    /**
     * The default catalogue (`SERVICE_TASKS`), in catalogue order.
     *
     * @return list<ServiceTaskFacts>
     */
    private static function catalogue(): array
    {
        return array_map(function (mixed $task): ServiceTaskFacts {
            $t = self::map($task);

            return new ServiceTaskFacts(self::str($t['id'] ?? null), self::str($t['name'] ?? null), self::num($t['intervalKm'] ?? 0), self::int($t['intervalMonths'] ?? 0), (bool) ($t['critical'] ?? false));
        }, self::list(WebFixtures::seed()['serviceTasks']));
    }

    /**
     * @param  list<NamedValue>  $rows
     * @return list<array<string, mixed>>
     */
    private static function named(array $rows, bool $money): array
    {
        return array_map(function (NamedValue $row) use ($money): array {
            $out = ['name' => $row->name, 'value' => $money ? ['cents' => (int) $row->value] : $row->value];

            return $row->meta === null ? $out : $out + ['meta' => $row->meta];
        }, $rows);
    }

    // -------------------------------------------------------------- values

    private static function today(mixed $value): DateTimeImmutable
    {
        return $value instanceof DateTimeImmutable ? $value : WebFixtures::frozenNow();
    }

    /** A fixture amount (WebMoney, or a plain peso number from the seed) in centavos. */
    private static function cents(mixed $value): int
    {
        if ($value instanceof WebMoney) {
            return $value->cents;
        }
        if (is_int($value) || is_float($value)) {
            return BigDecimal::of((string) json_encode($value))->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt();
        }

        throw new LogicException('Expected an amount in the fixture.');
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function map(mixed $value): array
    {
        return is_array($value) ? $value : throw new LogicException('Expected an object in the fixture.');
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        return array_map(self::str(...), self::list($value));
    }

    private static function int(mixed $value): int
    {
        return is_int($value) ? $value : throw new LogicException('Expected an integer in the fixture.');
    }

    private static function num(mixed $value): float|int
    {
        if ($value instanceof WebMoney) {
            return $value->money;
        }

        return is_int($value) || is_float($value) ? $value : throw new LogicException('Expected a number in the fixture.');
    }

    private static function str(mixed $value): string
    {
        return is_string($value) ? $value : throw new LogicException('Expected a string in the fixture.');
    }
}

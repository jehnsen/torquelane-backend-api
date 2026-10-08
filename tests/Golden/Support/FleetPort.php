<?php

declare(strict_types=1);

namespace Tests\Golden\Support;

use App\Domain\Alerts\Alert;
use App\Domain\Alerts\Alerts;
use App\Domain\Alerts\WorkOrderAlertFacts;
use App\Domain\Documents\DocumentFacts;
use App\Domain\Documents\DocumentKind;
use App\Domain\Fleet\CompletedService;
use App\Domain\Fleet\Compliance;
use App\Domain\Fleet\FleetSummary;
use App\Domain\Fleet\IntervalStatus;
use App\Domain\Fleet\OdometerValidation;
use App\Domain\Fleet\Pms;
use App\Domain\Fleet\PmsItem;
use App\Domain\Fleet\ServiceTaskFacts;
use App\Domain\Fleet\TaskState;
use App\Domain\Fleet\VehicleFacts;
use App\Domain\Fleet\VehicleHealth;
use App\Domain\Shared\Calendar;
use App\Domain\WorkOrders\WorkOrderCosting;
use DateTimeImmutable;
use LogicException;

/**
 * Calls the PHP fleet ports with a fixture's frontend-shaped arguments and
 * returns results in the fixture's shape, for exact comparison. Records the
 * frontend passed whole (a vehicle, a task) are echoed back as given, so a
 * comparison covers every field the port computes and nothing it does not.
 *
 * An argument the TypeScript defaulted to `new Date()` is the frozen clock.
 */
final class FleetPort
{
    /** module → functions replayed */
    public const array REPLAYED = [
        'pms' => ['evaluateTask', 'evaluateVehicle', 'evaluateFleet', 'summariseFleet', 'compareUrgency', 'odometerAgeDays', 'isOdometerStale', 'applyCompletion', 'workOrderCost', 'resolvePartsCost'],
        'interval-status' => ['computeIntervalStatus'],
        'odometer-validation' => ['validateOdometerReading'],
        'compliance' => ['vehicleComplianceStatus', 'documentExpiryStatus', 'expiringDocumentSummary', 'plateEndingRenewalMonth'],
        'alerts' => ['buildAlerts', 'viewAlerts'],
    ];

    /** The functions whose results are money, asserted in centavos. */
    public const array MONEY = ['workOrderCost', 'resolvePartsCost'];

    /**
     * @param  list<mixed>  $input
     */
    public static function call(string $fn, array $input): mixed
    {
        return match ($fn) {
            'evaluateTask' => self::item(Pms::evaluateTask(self::vehicle($input[0], self::today($input, 2)), self::task($input[1]), self::today($input, 2)), self::taskIndex([$input[1]])),
            'evaluateVehicle' => self::health(Pms::evaluateVehicle(self::vehicle($input[0], self::today($input, 1)), self::tasks($input[2] ?? null), self::today($input, 1)), $input[0], $input[2] ?? null),
            'evaluateFleet' => self::fleet($input),
            'summariseFleet' => self::summary(self::list($input[0])),
            'compareUrgency' => Pms::compareUrgency(self::itemFrom(self::map($input[0])), self::itemFrom(self::map($input[1]))),
            'odometerAgeDays' => Pms::odometerAgeDays(self::vehicle($input[0], self::today($input, 1)), self::today($input, 1)),
            'isOdometerStale' => Pms::isOdometerStale(self::vehicle($input[0], self::today($input, 1)), self::today($input, 1)),
            'applyCompletion' => self::completion(self::map($input[0]), self::map($input[1])),
            'workOrderCost' => self::cents(self::costOf(self::map($input[0]), true)),
            'resolvePartsCost' => self::cents(self::costOf(self::map($input[0]), false)),
            'computeIntervalStatus' => self::intervalStatus(self::map($input[0])),
            'validateOdometerReading' => self::odometer(self::map($input[0])),
            'vehicleComplianceStatus' => Compliance::vehicleStatus(
                self::str(self::map($input[0])['id'] ?? null),
                self::nullableStr(self::map($input[0])['driverLicenceExpiry'] ?? null),
                self::documents($input[1]),
                self::today($input, 2),
            ),
            'documentExpiryStatus' => Compliance::documentStatus(self::nullableStr(self::map($input[0])['expiresOn'] ?? null), self::today($input, 1)),
            'expiringDocumentSummary' => Compliance::expiringSummary(
                array_map(fn (mixed $v): ?string => self::nullableStr(self::map($v)['driverLicenceExpiry'] ?? null), self::list($input[0])),
                self::documents($input[1]),
                (int) $input[2],
                self::today($input, 3),
            ),
            'plateEndingRenewalMonth' => Compliance::plateEndingRenewalMonth(self::str($input[0])),
            'buildAlerts' => array_map(self::alertOut(...), Alerts::build(
                array_map(fn (mixed $h): VehicleHealth => self::healthFrom(self::map($h)), self::list($input[0])),
                array_map(self::workOrder(...), self::list($input[1])),
                self::documents($input[2]),
                (int) (self::map($input[3])['slaHours'] ?? 0),
                self::today($input, 4),
            )),
            'viewAlerts' => self::view(self::list($input[0]), self::map($input[1])),
            default => throw new LogicException("{$fn} is not replayed."),
        };
    }

    /**
     * @param  list<mixed>  $input
     */
    private static function today(array $input, int $index): DateTimeImmutable
    {
        $value = $input[$index] ?? null;

        return $value instanceof DateTimeImmutable ? $value : WebFixtures::frozenNow();
    }

    // ------------------------------------------------------------- vehicles

    private static function vehicle(mixed $vehicle, DateTimeImmutable $today): VehicleFacts
    {
        $v = self::map($vehicle);
        $state = [];
        foreach (self::map($v['taskState'] ?? []) as $taskId => $s) {
            $s = self::map($s);
            $state[(string) $taskId] = new TaskState(self::num($s['lastDoneOdometer'] ?? 0), self::str($s['lastDoneOn'] ?? null));
        }

        return new VehicleFacts(
            self::str($v['id'] ?? null),
            self::str($v['plateNumber'] ?? ''),
            self::num($v['odometer'] ?? 0),
            self::nullableStr($v['odometerReadAt'] ?? null) ?? Calendar::toDate($today),
            self::num($v['avgDailyKm'] ?? 0),
            $state,
            self::str($v['status'] ?? 'active'),
            self::nullableStr($v['driverLicenceExpiry'] ?? null),
            self::str($v['assignedTo'] ?? ''),
        );
    }

    private static function task(mixed $task): ServiceTaskFacts
    {
        $t = self::map($task);

        return new ServiceTaskFacts(self::str($t['id'] ?? null), self::str($t['name'] ?? null), self::num($t['intervalKm'] ?? 0), (int) ($t['intervalMonths'] ?? 0), (bool) ($t['critical'] ?? false));
    }

    /**
     * The catalogue a call evaluated against (the seed's, by default).
     *
     * @return list<ServiceTaskFacts>
     */
    private static function tasks(mixed $tasks): array
    {
        return array_map(self::task(...), self::list($tasks ?? WebFixtures::seed()['serviceTasks']));
    }

    /**
     * @param  list<mixed>  $tasks
     * @return array<string, mixed>
     */
    private static function taskIndex(array $tasks): array
    {
        $index = [];
        foreach ($tasks as $task) {
            $index[self::str(self::map($task)['id'] ?? null)] = $task;
        }

        return $index;
    }

    /**
     * @param  array<string, mixed>  $tasks  original task records by id
     * @return array<string, mixed>
     */
    private static function item(PmsItem $item, array $tasks): array
    {
        return [
            'task' => $tasks[$item->task->id] ?? throw new LogicException("Unknown task {$item->task->id}."),
            'status' => $item->status,
            'kmRemaining' => $item->kmRemaining,
            'daysRemaining' => $item->daysRemaining,
            'progress' => $item->progress,
            'dueOdometer' => $item->dueOdometer,
            'dueDate' => $item->dueDate,
            'governedBy' => $item->governedBy,
            'lastDoneOn' => $item->lastDoneOn,
            'lastDoneOdometer' => $item->lastDoneOdometer,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function health(VehicleHealth $health, mixed $vehicle, mixed $tasks): array
    {
        $index = self::taskIndex(self::list($tasks ?? WebFixtures::seed()['serviceTasks']));

        return [
            'vehicle' => $vehicle,
            'items' => array_map(fn (PmsItem $item): array => self::item($item, $index), $health->items),
            'status' => $health->status,
            'overdueCount' => $health->overdueCount,
            'dueSoonCount' => $health->dueSoonCount,
            'nextItem' => $health->nextItem === null ? null : self::item($health->nextItem, $index),
            'healthScore' => $health->healthScore,
        ];
    }

    /**
     * @param  list<mixed>  $input
     * @return list<array<string, mixed>>
     */
    private static function fleet(array $input): array
    {
        $today = self::today($input, 1);
        $vehicles = self::list($input[0]);
        $tasks = $input[2] ?? null;

        return array_map(
            fn (mixed $vehicle): array => self::health(Pms::evaluateVehicle(self::vehicle($vehicle, $today), self::tasks($tasks), $today), $vehicle, $tasks),
            $vehicles,
        );
    }

    /**
     * A fixture health record → the domain object (for summariseFleet, alerts).
     *
     * @param  array<string, mixed>  $h
     */
    private static function healthFrom(array $h): VehicleHealth
    {
        $items = array_map(fn (mixed $item): PmsItem => self::itemFrom(self::map($item)), self::list($h['items'] ?? []));

        return new VehicleHealth(
            self::vehicle($h['vehicle'] ?? null, WebFixtures::frozenNow()),
            $items,
            self::str($h['status'] ?? null),
            (int) ($h['overdueCount'] ?? 0),
            (int) ($h['dueSoonCount'] ?? 0),
            null,
            (int) ($h['healthScore'] ?? 0),
        );
    }

    /**
     * @param  array<string, mixed>  $i
     */
    private static function itemFrom(array $i): PmsItem
    {
        return new PmsItem(
            self::task($i['task'] ?? null),
            self::str($i['status'] ?? null),
            self::num($i['kmRemaining'] ?? 0),
            (int) ($i['daysRemaining'] ?? 0),
            self::num($i['progress'] ?? 0),
            self::num($i['dueOdometer'] ?? 0),
            self::str($i['dueDate'] ?? null),
            self::str($i['governedBy'] ?? null),
            self::str($i['lastDoneOn'] ?? null),
            self::num($i['lastDoneOdometer'] ?? 0),
        );
    }

    /**
     * @param  list<mixed>  $health
     * @return array<string, int|float>
     */
    private static function summary(array $health): array
    {
        $s = FleetSummary::of(array_map(fn (mixed $h): VehicleHealth => self::healthFrom(self::map($h)), $health));

        return [
            'total' => $s->total,
            'compliant' => $s->compliant,
            'dueSoon' => $s->dueSoon,
            'overdue' => $s->overdue,
            'inService' => $s->inService,
            'down' => $s->down,
            'complianceRate' => $s->complianceRate,
            'avgHealthScore' => $s->avgHealthScore,
            'totalOdometer' => $s->totalOdometer,
        ];
    }

    /**
     * @param  array<string, mixed>  $vehicle
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    private static function completion(array $vehicle, array $order): array
    {
        $today = WebFixtures::frozenNow();
        $result = Pms::applyCompletion(self::vehicle($vehicle, $today), new CompletedService(
            array_map(self::str(...), self::list($order['taskIds'] ?? [])),
            self::num($order['odometerAtService'] ?? 0),
            self::nullableStr($order['completedOn'] ?? null),
        ), $today);

        $taskState = [];
        foreach ($result->taskState as $id => $state) {
            $taskState[$id] = ['lastDoneOdometer' => $state->lastDoneOdometer, 'lastDoneOn' => $state->lastDoneOn];
        }

        return array_merge($vehicle, [
            'taskState' => $taskState,
            'odometer' => $result->odometer,
            'odometerReadAt' => $result->odometerReadAt,
            'status' => $result->status,
        ]);
    }

    // ---------------------------------------------------------------- money

    /**
     * @param  array<string, mixed>  $order
     */
    private static function costOf(array $order, bool $withLabour): int
    {
        $parts = null;
        if (is_array($order['parts'] ?? null) && $order['parts'] !== []) {
            $parts = array_map(fn (mixed $p): array => [
                'quantity' => self::decimal(self::map($p)['quantity'] ?? 0),
                'unitCost' => self::decimal(self::map($p)['unitCost'] ?? 0),
            ], self::list($order['parts']));
        }
        $aggregate = self::decimal($order['partsCost'] ?? 0);

        $money = $withLabour
            ? WorkOrderCosting::totalCost(self::decimal($order['laborCost'] ?? 0), $parts, $aggregate)
            : WorkOrderCosting::partsCost($parts, $aggregate);

        return $money->getMinorAmount()->toInt();
    }

    /**
     * @return array{cents: int}
     */
    private static function cents(int $cents): array
    {
        return ['cents' => $cents];
    }

    /** A fixture number (raw, or a WebMoney) as the decimal string the TypeScript meant. */
    private static function decimal(mixed $value): string
    {
        if ($value instanceof WebMoney) {
            $value = $value->money;
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return (string) json_encode($value);
        }

        throw new LogicException('Expected a number in the fixture.');
    }

    // ----------------------------------------------------- interval/odometer

    /**
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    private static function intervalStatus(array $in): array
    {
        $r = IntervalStatus::compute(
            self::date($in['lastCompletedAt'] ?? null),
            self::num($in['lastCompletedOdometer'] ?? 0),
            self::num($in['distanceIntervalKm'] ?? 0),
            (int) ($in['timeIntervalMonths'] ?? 0),
            self::num($in['currentOdometer'] ?? 0),
            self::date($in['currentOdometerReadAt'] ?? null),
            self::num($in['avgKmPerDay'] ?? 0),
            self::date($in['today'] ?? null),
        );

        return [
            'distanceDueAt' => $r->distanceDueAt,
            'distanceDueOn' => $r->distanceDueOn,
            'timeDueAt' => $r->timeDueAt,
            'governedBy' => $r->governedBy,
            'projectedDue' => $r->projectedDue,
            'status' => $r->status->value,
            'kmPast' => $r->kmPast,
            'daysOverdue' => $r->daysOverdue,
            'estimatedOdometerNow' => $r->estimatedOdometerNow,
            'daysRemaining' => $r->daysRemaining,
            'kmRemaining' => $r->kmRemaining,
            'progress' => $r->progress,
        ];
    }

    /**
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    private static function odometer(array $in): array
    {
        $readAt = ($in['readAt'] ?? null) instanceof DateTimeImmutable ? $in['readAt'] : WebFixtures::frozenNow();
        $result = OdometerValidation::validate(self::vehicle($in['vehicle'] ?? null, $readAt), self::num($in['reading'] ?? 0), $readAt);

        return ['status' => $result->status, 'error' => $result->error, 'warning' => $result->warning, 'impliedDailyKm' => $result->impliedDailyKm];
    }

    // ------------------------------------------------------ documents/alerts

    /**
     * @return list<DocumentFacts>
     */
    private static function documents(mixed $documents): array
    {
        return array_map(function (mixed $doc): DocumentFacts {
            $d = self::map($doc);

            return new DocumentFacts(
                self::str($d['id'] ?? null),
                self::str($d['name'] ?? ''),
                DocumentKind::from(self::str($d['kind'] ?? null)),
                self::nullableStr($d['vehicleId'] ?? null),
                self::nullableStr($d['expiresOn'] ?? null),
            );
        }, self::list($documents));
    }

    private static function workOrder(mixed $order): WorkOrderAlertFacts
    {
        $o = self::map($order);
        $pending = count(array_filter(self::list($o['lines'] ?? []), fn (mixed $line): bool => (self::map($line)['approvalStatus'] ?? null) === 'pending'));

        return new WorkOrderAlertFacts(
            self::str($o['id'] ?? null),
            self::str($o['reference'] ?? ''),
            self::str($o['title'] ?? ''),
            self::str($o['status'] ?? null),
            self::str($o['scheduledFor'] ?? null),
            self::str($o['vehicleId'] ?? null),
            self::nullableStr($o['pendingApprovalEnteredAt'] ?? null),
            $pending,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function alertOut(Alert $alert): array
    {
        return [
            'id' => $alert->id,
            'kind' => $alert->kind,
            'severity' => $alert->severity,
            'title' => $alert->title,
            'body' => $alert->body,
            'vehicleId' => $alert->vehicleId,
            'href' => $alert->href,
            'daysRemaining' => $alert->daysRemaining,
        ];
    }

    private static function alertIn(mixed $alert): Alert
    {
        $a = self::map($alert);

        return new Alert(self::str($a['id'] ?? null), self::str($a['kind'] ?? null), self::str($a['severity'] ?? null), self::str($a['title'] ?? null), self::str($a['body'] ?? null), self::nullableStr($a['vehicleId'] ?? null), self::str($a['href'] ?? null), (int) ($a['daysRemaining'] ?? 0));
    }

    /**
     * @param  list<mixed>  $alerts
     * @param  array<string, mixed>  $interaction
     * @return array<string, mixed>
     */
    private static function view(array $alerts, array $interaction): array
    {
        $view = Alerts::view(
            array_map(self::alertIn(...), $alerts),
            array_map(self::str(...), self::list($interaction['readIds'] ?? [])),
            array_map(self::str(...), self::list($interaction['dismissedIds'] ?? [])),
        );

        return [
            'all' => array_map(self::alertOut(...), $view->all),
            'visible' => array_map(self::alertOut(...), $view->visible),
            'unread' => array_map(self::alertOut(...), $view->unread),
            'unreadCount' => $view->unreadCount,
            'dismissedCount' => $view->dismissedCount,
        ];
    }

    // ---------------------------------------------------------------- casts

    private static function date(mixed $value): DateTimeImmutable
    {
        return $value instanceof DateTimeImmutable ? $value : throw new LogicException('Expected a date in the fixture.');
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

    private static function nullableStr(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}

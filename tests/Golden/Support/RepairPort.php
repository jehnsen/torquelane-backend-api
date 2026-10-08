<?php

declare(strict_types=1);

namespace Tests\Golden\Support;

use App\Domain\Access\AccessMatrix;
use App\Domain\Access\Role;
use App\Domain\Approvals\Approvals;
use App\Domain\Approvals\ApprovalSettings;
use App\Domain\Approvals\LineApprovalStatus;
use App\Domain\Billing\BillableLine;
use App\Domain\Billing\Billing;
use App\Domain\Billing\BillingTotals;
use App\Domain\CheckIn\CheckIn;
use App\Domain\CheckIn\CheckInCandidate;
use App\Domain\CheckIn\CheckInCustomer;
use App\Domain\CheckIn\CheckInForm;
use App\Domain\CheckIn\CheckInResult;
use App\Domain\Fleet\PmsItem;
use App\Domain\Fleet\ServiceTaskFacts;
use App\Domain\Fleet\VehicleFacts;
use App\Domain\Fleet\VehicleHealth;
use App\Domain\Shared\BusinessHours;
use App\Domain\Shared\Calendar;
use App\Domain\Shared\Num;
use App\Domain\Shop\AccountRef;
use App\Domain\Shop\AccountRollup;
use App\Domain\Shop\BayFacts;
use App\Domain\Shop\BayLoad;
use App\Domain\Shop\NamedValue;
use App\Domain\Shop\Shop;
use App\Domain\Shop\ShopContext;
use App\Domain\Shop\TechnicianLoad;
use App\Domain\Shop\UtilisationPoint;
use App\Domain\WorkOrders\PartFacts;
use App\Domain\WorkOrders\PartsSource;
use App\Domain\WorkOrders\StatusEvent;
use App\Domain\WorkOrders\WorkOrderFacts;
use App\Domain\WorkOrders\WorkOrderMachine;
use App\Domain\WorkOrders\WorkOrderReference;
use App\Domain\WorkOrders\WorkOrderStatus;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use LogicException;
use WeakMap;

/**
 * Calls the PHP repair ports (work orders, approvals, billing, check-in,
 * shop) with a fixture's frontend-shaped arguments and returns results in
 * the fixture's shape. Records passed whole (an order, a vehicle, a client)
 * are echoed back exactly as given, so a comparison covers what the port
 * computes and nothing it does not.
 *
 * Money crosses in integer centavos: a peso amount in, `{cents}` out. An
 * input amount that is not a whole number of centavos cannot be represented
 * (rates are stored in centavos) and raises SubCentavoInput.
 */
final class RepairPort
{
    public const array REPLAYED = [
        'work-order-machine' => ['lifecycleStage', 'canTransition', 'checkTransition', 'capabilityFor', 'nextReference', 'hasReference', 'displayReference', 'assignOnApproval', 'isInHouse', '$authorizeTransition', 'nextStatuses'],
        'approvals' => ['requiredApprover', 'canApprove', 'deriveOrderStatus', 'varianceExceeds', 'businessHoursBetween', 'pendingValue', 'approvedValue', 'declinedValue', 'lineCost', 'sumLinesByStatus'],
        'billing' => ['withRates', 'linePartAmount', 'lineLabourAmount', 'lineAmount', 'recalcLine', 'computeTotals', 'totalsFromSubtotal', 'approvedGrandTotal', 'roundMoney'],
        'checkin' => ['normalisePlate', 'normaliseVin', 'lookupVehicle', 'hydrateCheckInForm', 'suggestedWorkAtCheckIn'],
        'shop' => ['rollupClients', 'isActiveJob', 'elapsedMinutes', 'formatDuration', 'bayLoadFor', 'floorUtilisation', 'awaitingApproval', 'readyForCollection', 'revenueBetween', 'technicianLoad', 'partsMargin', 'revenueByServiceItem', 'utilisationSeries', 'authorisedValue', 'estimatedHours', 'startedAt', 'finishedAt', 'jobsScheduledFor', 'arrivingToday', 'today', 'inProgress', 'revenueByClient', 'approvalTurnaroundByClient', 'serviceableItems'],
    ];

    /** @var WeakMap<object, mixed>|null domain object → the fixture record it came from */
    private static ?WeakMap $origin = null;

    /**
     * @param  list<mixed>  $input
     */
    public static function call(string $fn, array $input): mixed
    {
        self::$origin = new WeakMap;

        return match ($fn) {
            // ------------------------------------------------ work-order machine
            'lifecycleStage' => WorkOrderMachine::lifecycleStage(self::status(self::map($input[0])['status']), self::truthy(self::map($input[0])['collectedAt'] ?? null))->value,
            'canTransition' => WorkOrderMachine::canTransition(self::status($input[0]), self::status($input[1])),
            'checkTransition' => self::transition(self::map($input[0]), self::status($input[1])),
            'capabilityFor' => WorkOrderMachine::capabilityFor(self::status($input[0]))?->value,
            'nextReference' => WorkOrderReference::next(
                array_map(fn (mixed $o): string => self::str(self::map($o)['reference'] ?? null), self::list($input[0])),
                is_int($input[1] ?? null) ? $input[1] : (int) WebFixtures::frozenNow()->format('Y'),
            ),
            'hasReference' => WorkOrderReference::has(self::str(self::map($input[0])['reference'] ?? null)),
            'displayReference' => WorkOrderReference::display(self::str(self::map($input[0])['reference'] ?? null)),
            'assignOnApproval' => self::assignment(WorkOrderMachine::assignOnApproval(self::str(self::map($input[0])['vendor'] ?? null), self::str($input[1]))),
            'isInHouse' => WorkOrderMachine::isInHouse(self::str(self::map($input[0])['vendor'] ?? null)),
            '$authorizeTransition' => self::authorize(self::map($input[0]), self::status($input[1]), Role::from(self::str($input[2]))),
            'nextStatuses' => array_map(fn (WorkOrderStatus $s): string => $s->value, WorkOrderMachine::nextStatuses(self::status($input[0]))),

            // -------------------------------------------------------- approvals
            'requiredApprover' => Approvals::requiredApprover(self::cents($input[0]), self::settings($input[1]))->value,
            'canApprove' => Approvals::canApprove(Role::from(self::str($input[0])), self::cents($input[1]), self::settings($input[2])),
            'deriveOrderStatus' => Approvals::deriveOrderStatus(array_map(fn (BillableLine $l): LineApprovalStatus => $l->approvalStatus, self::lines($input[0])))->value,
            'varianceExceeds' => Approvals::varianceExceeds(self::cents($input[0]), self::cents($input[1]), self::decimal($input[2])),
            'businessHoursBetween' => BusinessHours::between(self::date($input[0]), self::date($input[1])),
            'pendingValue' => self::money(Approvals::pendingValue(self::lines($input[0]))),
            'approvedValue' => self::money(Approvals::approvedValue(self::lines($input[0]))),
            'declinedValue' => self::money(Approvals::declinedValue(self::lines($input[0]))),
            'lineCost' => self::money(self::line($input[0])->cost()),
            'sumLinesByStatus' => self::money(Approvals::sumByStatus(self::lines($input[0]), LineApprovalStatus::from(self::str($input[1])))),

            // ---------------------------------------------------------- billing
            'roundMoney' => self::money(Billing::roundCents(BigDecimal::of(self::decimal($input[0]))->multipliedBy(100))),
            'linePartAmount' => self::money(Billing::linePartCents(self::decimal(self::map($input[0])['quantity'] ?? null), self::cents(self::map($input[0])['unitPartRate'] ?? null))),
            'lineLabourAmount' => self::money(Billing::lineLabourCents(self::decimal(self::map($input[0])['labourHours'] ?? null), self::cents(self::map($input[0])['labourRate'] ?? null))),
            'lineAmount' => self::money(Billing::lineCents(self::line($input[0]))),
            'recalcLine' => self::recalcOut(self::map($input[0])),
            'withRates' => self::withRatesOut(self::map($input[0])),
            'computeTotals' => self::totals(Billing::totals(
                self::lines($input[0]),
                self::decimal(self::map($input[1])['vatRatePct'] ?? null),
                self::cents(self::map($input[1])['miscFeeFlat'] ?? null),
                ($input[2] ?? null) === null ? null : array_map(fn (mixed $s): LineApprovalStatus => LineApprovalStatus::from(self::str($s)), self::list($input[2])),
            )),
            'totalsFromSubtotal' => self::totals(Billing::totalsFromSubtotal(
                self::cents($input[0]),
                self::cents($input[1]),
                self::decimal(self::map($input[2])['vatRatePct'] ?? null),
                self::cents(self::map($input[2])['miscFeeFlat'] ?? null),
            )),
            'approvedGrandTotal' => self::money(Billing::approvedGrandTotal(
                self::lines($input[0]),
                self::decimal(self::map($input[1])['vatRatePct'] ?? null),
                self::cents(self::map($input[1])['miscFeeFlat'] ?? null),
            )),

            // --------------------------------------------------------- check-in
            'normalisePlate' => CheckIn::normalisePlate(self::str($input[0])),
            'normaliseVin' => CheckIn::normaliseVin(self::str($input[0])),
            'lookupVehicle' => self::lookup($input),
            'hydrateCheckInForm' => self::form(CheckIn::hydrate(self::resultIn(self::map($input[0])), self::customer(self::map($input[0])['client'] ?? null))),
            'suggestedWorkAtCheckIn' => self::echoAll(CheckIn::suggestedWork(self::healthIn($input[0] ?? null), is_int($input[1] ?? null) ? $input[1] : 5)),

            // ------------------------------------------------------------- shop
            'isActiveJob' => Shop::isActiveJob(self::job($input[0])),
            'startedAt' => Shop::startedAt(self::job($input[0])),
            'finishedAt' => Shop::finishedAt(self::job($input[0])),
            'elapsedMinutes' => Shop::elapsedMinutes(self::job($input[0]), self::now($input, 1)),
            'formatDuration' => Shop::formatDuration(is_int($input[0] ?? null) ? $input[0] : null),
            'estimatedHours' => Shop::estimatedHours(self::job($input[0]), self::shop()),
            'jobsScheduledFor' => self::echoAll(Shop::jobsScheduledFor(self::jobs($input[0]), self::date($input[1]))),
            'bayLoadFor' => array_map(self::bayLoad(...), Shop::bayLoadFor(self::jobs($input[0]), self::date($input[1]), self::shop())),
            'floorUtilisation' => self::floor(self::jobs($input[0]), self::date($input[1])),
            'arrivingToday' => self::echoAll(Shop::arrivingToday(self::jobs($input[0]), self::now($input, 1))),
            'inProgress' => self::echoAll(Shop::inProgress(self::jobs($input[0]))),
            'readyForCollection' => self::echoAll(Shop::readyForCollection(self::jobs($input[0]))),
            'awaitingApproval' => self::awaiting(self::jobs($input[0]), self::now($input, 1)),
            'revenueBetween' => self::money(Shop::revenueBetween(self::jobs($input[0]), self::date($input[1]), self::date($input[2]))),
            'rollupClients' => self::rollup($input),
            'technicianLoad' => array_map(self::technician(...), Shop::technicianLoad(
                array_map(self::str(...), self::list($input[0])),
                self::jobs($input[1]),
                self::date($input[2]),
                self::date($input[3]),
                self::shop(),
            )),
            'revenueByClient' => array_map(self::moneyRow(...), Shop::revenueByAccount(self::accounts($input[0]), self::jobs($input[2], self::accountOf($input[1])), self::date($input[3]), self::date($input[4]))),
            'revenueByServiceItem' => array_map(self::moneyRow(...), Shop::revenueByServiceItem(self::jobs($input[0]), self::date($input[1]), self::date($input[2]), self::shop())),
            'utilisationSeries' => array_map(fn (UtilisationPoint $p): array => ['key' => $p->key, 'label' => $p->label, 'utilisation' => $p->utilisation, 'bookedHours' => $p->bookedHours], Shop::utilisationSeries(self::jobs($input[0]), self::int($input[1]), self::now($input, 2), self::shop())),
            'approvalTurnaroundByClient' => array_map(fn (NamedValue $row): array => ['name' => $row->name, 'value' => $row->value, 'meta' => $row->meta], Shop::approvalTurnaroundByAccount(self::accounts($input[0]), self::jobs($input[2], self::accountOf($input[1])))),
            'partsMargin' => (function (array $orders): array {
                $m = Shop::partsMargin($orders);

                return ['supplierProvidedValue' => self::money($m->supplierProvidedCents), 'ownStockValue' => self::money($m->ownStockCents), 'margin' => self::money($m->marginCents)];
            })(self::jobs($input[0])),
            'serviceableItems' => self::echoAll(Shop::serviceableItems(self::healthIn($input[0] ?? null))),
            'authorisedValue' => self::money(Shop::authorisedValue(self::job($input[0]))),
            'today' => Shop::today(self::now($input, 0)),

            default => throw new LogicException("{$fn} is not replayed."),
        };
    }

    // ------------------------------------------------------- work orders

    /**
     * @param  array<array-key, mixed>  $order
     * @return array<string, mixed>
     */
    private static function transition(array $order, WorkOrderStatus $to): array
    {
        $check = WorkOrderMachine::checkTransition(self::status($order['status'] ?? null), count(self::list($order['lines'] ?? [])), $to);

        return $check->ok ? ['ok' => true, 'capability' => $check->capability?->value] : ['ok' => false, 'reason' => $check->reason];
    }

    /**
     * @param  array<array-key, mixed>  $order
     * @return array<string, mixed>
     */
    private static function authorize(array $order, WorkOrderStatus $to, Role $role): array
    {
        $check = WorkOrderMachine::checkTransition(self::status($order['status'] ?? null), count(self::list($order['lines'] ?? [])), $to);
        $roleHas = ! $check->ok ? null : ($check->capability === null || AccessMatrix::can($role, $check->capability));

        return [
            'transition' => $check->ok ? ['ok' => true, 'capability' => $check->capability?->value] : ['ok' => false, 'reason' => $check->reason],
            'roleHasCapability' => $roleHas,
            'allowed' => $check->ok && $roleHas === true,
        ];
    }

    /**
     * @param  array{assigned_branch_id: string, vendor: string}  $assignment
     * @return array<string, string>
     */
    private static function assignment(array $assignment): array
    {
        return ['assignedProviderId' => $assignment['assigned_branch_id'], 'vendor' => $assignment['vendor']];
    }

    // ----------------------------------------------------------- approvals

    private static function settings(mixed $settings): ApprovalSettings
    {
        $s = self::map($settings);

        return ApprovalSettings::fromArray([
            'auto_approve_under_cents' => self::cents($s['autoApproveUnder'] ?? null),
            'ops_approval_under_cents' => self::cents($s['opsApprovalUnder'] ?? null),
            'sla_hours' => self::int($s['slaHours'] ?? null),
            'variance_threshold_pct' => self::decimal($s['varianceThresholdPct'] ?? null),
            'default_parts_source' => self::str($s['defaultPartsSource'] ?? null),
            'monthly_budget_cents' => self::cents($s['monthlyBudget'] ?? null),
            'vat_rate_pct' => self::decimal($s['vatRatePct'] ?? 0),
            'misc_fee_flat_cents' => self::cents($s['miscFeeFlat'] ?? 0),
            'default_labour_rate_cents' => self::cents($s['defaultLabourRate'] ?? 0),
        ]);
    }

    // ------------------------------------------------------------- billing

    /**
     * @return list<BillableLine>
     */
    private static function lines(mixed $lines): array
    {
        return array_map(self::line(...), self::list($lines));
    }

    private static function line(mixed $line): BillableLine
    {
        $l = self::map($line);
        $object = new BillableLine(
            self::decimal($l['quantity'] ?? 1),
            self::cents($l['unitPartRate'] ?? 0),
            self::decimal($l['labourHours'] ?? 0),
            self::cents($l['labourRate'] ?? 0),
            self::cents($l['partCost'] ?? 0),
            self::cents($l['labourCost'] ?? 0),
            LineApprovalStatus::from(self::str($l['approvalStatus'] ?? 'pending')),
            PartsSource::from(self::str($l['partsSource'] ?? 'supplier_provided')),
        );
        self::remember($object, $line);

        return $object;
    }

    /**
     * @param  array<array-key, mixed>  $l
     * @return array<array-key, mixed>
     */
    private static function recalcOut(array $l): array
    {
        $line = Billing::recalc(self::line($l));

        return array_merge($l, ['partCost' => self::money($line->partCostCents), 'labourCost' => self::money($line->labourCostCents)]);
    }

    /**
     * @param  array<array-key, mixed>  $l
     * @return array<array-key, mixed>
     */
    private static function withRatesOut(array $l): array
    {
        $rates = Billing::withRates(
            self::cents($l['partCost'] ?? 0),
            self::cents($l['labourCost'] ?? 0),
            ($l['quantity'] ?? null) === null ? null : self::decimal($l['quantity']),
            ($l['unitPartRate'] ?? null) === null ? null : self::cents($l['unitPartRate']),
            ($l['labourHours'] ?? null) === null ? null : self::decimal($l['labourHours']),
            ($l['labourRate'] ?? null) === null ? null : self::cents($l['labourRate']),
        );

        return array_merge($l, [
            'quantity' => Num::of(BigDecimal::of($rates['quantity'])),
            'unitPartRate' => self::money($rates['unit_part_rate_cents']),
            'labourHours' => Num::of(BigDecimal::of($rates['labour_hours'])),
            'labourRate' => self::money($rates['labour_rate_cents']),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function totals(BillingTotals $t): array
    {
        return [
            'partsTotal' => self::money($t->partsTotalCents),
            'labourTotal' => self::money($t->labourTotalCents),
            'subTotal' => self::money($t->subTotalCents),
            'miscTotal' => self::money($t->miscTotalCents),
            'taxTotal' => self::money($t->taxTotalCents),
            'vatRatePct' => Num::of(BigDecimal::of($t->vatRatePct)),
            'grandTotal' => self::money($t->grandTotalCents),
        ];
    }

    // ------------------------------------------------------------ check-in

    /**
     * @param  list<mixed>  $input
     * @return array<string, mixed>
     */
    private static function lookup(array $input): array
    {
        $candidates = array_map(self::candidate(...), self::list($input[1]));
        $clients = self::list($input[2] ?? []);
        $result = CheckIn::lookup(self::str($input[0]), $candidates, self::now($input, 3));

        if ($result->outcome === 'idle') {
            return ['outcome' => 'idle'];
        }
        if ($result->outcome === 'new' || $result->candidate === null) {
            return ['outcome' => 'new', 'plateNumber' => $result->plateNumber, 'vin' => $result->vin];
        }

        $client = null;
        foreach ($clients as $c) {
            if ((self::map($c)['id'] ?? null) === $result->candidate->customerAccountId) {
                $client = $c;
                break;
            }
        }

        return [
            'outcome' => 'existing',
            'matchedOn' => $result->matchedOn,
            'vehicle' => self::origin($result->candidate),
            'client' => $client,
            'lastOdometer' => $result->lastOdometer,
            'lastOdometerReadAt' => $result->lastOdometerReadAt,
            'odometerAgeDays' => $result->odometerAgeDays,
            'odometerStale' => $result->odometerStale,
        ];
    }

    private static function candidate(mixed $vehicle): CheckInCandidate
    {
        $v = self::map($vehicle);
        $candidate = new CheckInCandidate(
            self::vehicleFacts($v),
            self::str($v['vin'] ?? ''),
            is_string($v['fleetClientId'] ?? null) ? $v['fleetClientId'] : null,
            self::str($v['make'] ?? ''),
            self::str($v['model'] ?? ''),
            is_int($v['year'] ?? null) ? $v['year'] : null,
            is_string($v['vehicleClass'] ?? null) ? $v['vehicleClass'] : null,
            is_string($v['fuelType'] ?? null) ? $v['fuelType'] : null,
        );
        self::remember($candidate, $vehicle);

        return $candidate;
    }

    /**
     * @param  array<array-key, mixed>  $v
     */
    private static function vehicleFacts(array $v): VehicleFacts
    {
        return new VehicleFacts(
            self::str($v['id'] ?? ''),
            self::str($v['plateNumber'] ?? ''),
            self::num($v['odometer'] ?? 0),
            self::str($v['odometerReadAt'] ?? Calendar::toDate(WebFixtures::frozenNow())),
            self::num($v['avgDailyKm'] ?? 0),
            [],
            self::str($v['status'] ?? 'active'),
            is_string($v['driverLicenceExpiry'] ?? null) ? $v['driverLicenceExpiry'] : null,
            self::str($v['assignedTo'] ?? ''),
        );
    }

    /**
     * @param  array<array-key, mixed>  $r
     */
    private static function resultIn(array $r): CheckInResult
    {
        return match ($r['outcome'] ?? null) {
            'idle' => CheckInResult::idle(),
            'new' => CheckInResult::new(self::str($r['plateNumber'] ?? ''), is_string($r['vin'] ?? null) ? $r['vin'] : null),
            default => new CheckInResult(
                'existing',
                matchedOn: self::str($r['matchedOn'] ?? null),
                candidate: self::candidate($r['vehicle'] ?? null),
                lastOdometer: self::num($r['lastOdometer'] ?? null),
                lastOdometerReadAt: self::str($r['lastOdometerReadAt'] ?? null),
                odometerAgeDays: self::int($r['odometerAgeDays'] ?? null),
                odometerStale: (bool) ($r['odometerStale'] ?? false),
            ),
        };
    }

    private static function customer(mixed $client): ?CheckInCustomer
    {
        if (! is_array($client)) {
            return null;
        }

        return new CheckInCustomer(self::str($client['name'] ?? ''), self::str($client['contactName'] ?? ''), self::str($client['contactEmail'] ?? ''));
    }

    /**
     * @return array<string, mixed>
     */
    private static function form(CheckInForm $f): array
    {
        return [
            'vehicleId' => $f->vehicleId,
            'plateNumber' => $f->plateNumber,
            'vin' => $f->vin,
            'make' => $f->make,
            'model' => $f->model,
            'year' => $f->year,
            'vehicleClass' => $f->vehicleClass,
            'fuelType' => $f->fuelType,
            'customerName' => $f->customerName,
            'customerContact' => $f->customerContact,
            'customerEmail' => $f->customerEmail,
            'assignedTo' => $f->assignedTo,
            'odometer' => $f->odometer,
            'odometerNeedsConfirmation' => $f->odometerNeedsConfirmation,
            'isExistingVehicle' => $f->isExistingVehicle,
        ];
    }

    /** A fixture health record (or a partial one: items with a status) → domain objects remembered by origin. */
    private static function healthIn(mixed $health): ?VehicleHealth
    {
        if (! is_array($health)) {
            return null;
        }
        $items = array_map(function (mixed $item): PmsItem {
            $i = self::map($item);
            $task = self::map($i['task'] ?? []);
            $object = new PmsItem(
                new ServiceTaskFacts(self::str($task['id'] ?? ''), self::str($task['name'] ?? ''), 0, 0, false),
                self::str($i['status'] ?? null),
                0, 0, 0, 0, '', '', '', 0,
            );
            self::remember($object, $item);

            return $object;
        }, self::list($health['items'] ?? []));

        return new VehicleHealth(self::vehicleFacts(is_array($health['vehicle'] ?? null) ? $health['vehicle'] : []), $items, 'ok', 0, 0, null, 100);
    }

    // ---------------------------------------------------------------- shop

    private static function shop(): ShopContext
    {
        static $shop = null;
        if ($shop instanceof ShopContext) {
            return $shop;
        }

        $constants = [];
        foreach (WebFixtures::raw('shop') as $case) {
            if ($case['fn'] === '$constants') {
                $constants = self::map($case['output']);
            }
        }
        $bays = array_map(function (mixed $bay): BayFacts {
            $b = self::map($bay);

            return new BayFacts(self::str($b['id'] ?? null), self::str($b['name'] ?? null), self::num($b['capacityHoursPerDay'] ?? null));
        }, self::list($constants['BAYS'] ?? []));

        $hours = [];
        $names = [];
        foreach (self::list(WebFixtures::seed()['serviceTasks']) as $task) {
            $t = self::map($task);
            $hours[self::str($t['id'] ?? null)] = self::num($t['estimatedHours'] ?? 0);
            $names[self::str($t['id'] ?? null)] = self::str($t['name'] ?? null);
        }

        return $shop = new ShopContext($bays, $hours, $names, 65_000);
    }

    /**
     * @param  array<string, string>  $accountOf  vehicle id → client id
     * @return list<WorkOrderFacts>
     */
    private static function jobs(mixed $orders, array $accountOf = []): array
    {
        return array_map(fn (mixed $o): WorkOrderFacts => self::job($o, $accountOf), self::list($orders));
    }

    /**
     * @param  array<string, string>  $accountOf
     */
    private static function job(mixed $order, array $accountOf = []): WorkOrderFacts
    {
        $o = self::map($order);
        $vehicleId = self::str($o['vehicleId'] ?? '');
        $job = new WorkOrderFacts(
            self::str($o['id'] ?? ''),
            self::status($o['status'] ?? null),
            $vehicleId,
            $accountOf[$vehicleId] ?? null,
            is_string($o['scheduledFor'] ?? null) && $o['scheduledFor'] !== '' ? $o['scheduledFor'] : null,
            is_string($o['bayId'] ?? null) ? $o['bayId'] : null,
            array_map(self::str(...), self::list($o['taskIds'] ?? [])),
            self::cents($o['laborCost'] ?? 0),
            self::cents($o['partsCost'] ?? 0),
            array_map(fn (mixed $p): PartFacts => new PartFacts(self::decimal(self::map($p)['quantity'] ?? 0), self::cents(self::map($p)['unitCost'] ?? 0)), self::list($o['parts'] ?? [])),
            self::lines($o['lines'] ?? []),
            array_map(fn (mixed $e): StatusEvent => new StatusEvent(self::status(self::map($e)['status'] ?? null), self::instant(self::map($e)['at'] ?? null)), self::list($o['history'] ?? [])),
            is_string($o['completedOn'] ?? null) ? $o['completedOn'] : null,
            is_string($o['collectedAt'] ?? null) ? self::instant($o['collectedAt']) : null,
            self::str($o['technician'] ?? ''),
            is_string($o['pendingApprovalEnteredAt'] ?? null) ? self::instant($o['pendingApprovalEnteredAt']) : null,
            is_int($o['approvalWaitHours'] ?? null) || is_float($o['approvalWaitHours'] ?? null) ? $o['approvalWaitHours'] : null,
        );
        self::remember($job, $order);

        return $job;
    }

    /**
     * @return array<string, string>
     */
    private static function accountOf(mixed $vehicles): array
    {
        $map = [];
        foreach (self::list($vehicles) as $vehicle) {
            $v = self::map($vehicle);
            $map[self::str($v['id'] ?? null)] = self::str($v['fleetClientId'] ?? null);
        }

        return $map;
    }

    /**
     * @return list<AccountRef>
     */
    private static function accounts(mixed $clients): array
    {
        return array_map(function (mixed $client): AccountRef {
            $c = self::map($client);
            $ref = new AccountRef(self::str($c['id'] ?? null), self::str($c['name'] ?? null));
            self::remember($ref, $client);

            return $ref;
        }, self::list($clients));
    }

    /**
     * @param  list<mixed>  $input
     * @return list<array<string, mixed>>
     */
    private static function rollup(array $input): array
    {
        $counts = [];
        foreach (self::accountOf($input[1]) as $accountId) {
            $counts[$accountId] = ($counts[$accountId] ?? 0) + 1;
        }

        return array_map(fn (AccountRollup $r): array => [
            'client' => self::origin($r->account),
            'vehicleCount' => $r->vehicleCount,
            'openWorkOrders' => $r->openWorkOrders,
            'avgApprovalHours' => $r->avgApprovalHours,
            'spendThisPeriod' => self::money($r->spendThisPeriodCents),
            'outstanding' => self::money($r->outstandingCents),
        ], Shop::rollupAccounts(self::accounts($input[0]), $counts, self::jobs($input[2], self::accountOf($input[1])), self::date($input[3]), self::date($input[4])));
    }

    /**
     * @return array<string, mixed>
     */
    private static function bayLoad(BayLoad $load): array
    {
        return [
            'bayId' => $load->bayId,
            'name' => $load->name,
            'bookedHours' => $load->bookedHours,
            'capacityHours' => $load->capacityHours,
            'utilisation' => $load->utilisation,
            'jobs' => self::echoAll($load->jobs),
        ];
    }

    /**
     * @param  list<WorkOrderFacts>  $jobs
     * @return array<string, mixed>
     */
    private static function floor(array $jobs, DateTimeImmutable $day): array
    {
        $floor = Shop::floorUtilisation($jobs, $day, self::shop());

        return [
            'bookedHours' => $floor->bookedHours,
            'capacityHours' => $floor->capacityHours,
            'utilisation' => $floor->utilisation,
            'loads' => array_map(self::bayLoad(...), $floor->loads),
        ];
    }

    /**
     * @param  list<WorkOrderFacts>  $jobs
     * @return array<string, mixed>
     */
    private static function awaiting(array $jobs, DateTimeImmutable $now): array
    {
        $a = Shop::awaitingApproval($jobs, $now);

        return [
            'orders' => self::echoAll($a->orders),
            'count' => $a->count,
            'totalValue' => self::money($a->totalValueCents),
            'longest' => $a->longest === null ? null : ['order' => self::origin($a->longest->order), 'hours' => $a->longest->hours],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function technician(TechnicianLoad $t): array
    {
        return [
            'name' => $t->technician,
            'current' => $t->current === null ? null : self::origin($t->current),
            'completedThisPeriod' => $t->completedThisPeriod,
            'avgActualHours' => $t->avgActualHours,
            'avgEstimatedHours' => $t->avgEstimatedHours,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function moneyRow(NamedValue $row): array
    {
        return ['name' => $row->name, 'value' => self::money((int) $row->value)];
    }

    // -------------------------------------------------------------- casts

    private static function remember(object $object, mixed $origin): void
    {
        if (self::$origin !== null) {
            self::$origin[$object] = $origin;
        }
    }

    private static function origin(object $object): mixed
    {
        return self::$origin !== null && isset(self::$origin[$object]) ? self::$origin[$object] : throw new LogicException('Unknown origin.');
    }

    /**
     * @param  list<object>  $objects
     * @return list<mixed>
     */
    private static function echoAll(array $objects): array
    {
        return array_map(self::origin(...), $objects);
    }

    /**
     * @return array{cents: int}
     */
    private static function money(int $cents): array
    {
        return ['cents' => $cents];
    }

    /** A fixture peso amount (raw or WebMoney) → whole centavos, exactly, or SubCentavoInput. */
    private static function cents(mixed $value): int
    {
        $centavos = BigDecimal::of(self::decimal($value))->multipliedBy(100)->strippedOfTrailingZeros();
        if ($centavos->getScale() > 0) {
            throw new SubCentavoInput("{$centavos} centavos");
        }

        return $centavos->toInt();
    }

    /** A fixture number as the decimal string the TypeScript meant. */
    private static function decimal(mixed $value): string
    {
        if ($value instanceof WebMoney) {
            $value = $value->money;
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return (string) BigDecimal::of((string) json_encode($value));
        }

        throw new LogicException('Expected a number in the fixture.');
    }

    private static function status(mixed $value): WorkOrderStatus
    {
        return WorkOrderStatus::from(self::str($value));
    }

    private static function now(array $input, int $index): DateTimeImmutable
    {
        $value = $input[$index] ?? null;

        return $value instanceof DateTimeImmutable ? $value : WebFixtures::frozenNow();
    }

    private static function date(mixed $value): DateTimeImmutable
    {
        return $value instanceof DateTimeImmutable ? $value : throw new LogicException('Expected a date in the fixture.');
    }

    private static function instant(mixed $value): DateTimeImmutable
    {
        return Calendar::local(new DateTimeImmutable(self::str($value)));
    }

    private static function truthy(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== false && $value !== 0;
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

    private static function int(mixed $value): int
    {
        return is_int($value) ? $value : throw new LogicException('Expected an integer in the fixture.');
    }

    private static function str(mixed $value): string
    {
        return is_string($value) ? $value : throw new LogicException('Expected a string in the fixture.');
    }
}

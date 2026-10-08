<?php

declare(strict_types=1);

namespace App\Domain\Shop;

use App\Domain\Approvals\Approvals;
use App\Domain\Approvals\LineApprovalStatus;
use App\Domain\Fleet\PmsItem;
use App\Domain\Fleet\VehicleHealth;
use App\Domain\Shared\BusinessHours;
use App\Domain\Shared\Calendar;
use App\Domain\Shared\JsMath;
use App\Domain\WorkOrders\PartsSource;
use App\Domain\WorkOrders\StatusEvent;
use App\Domain\WorkOrders\WorkOrderFacts;
use App\Domain\WorkOrders\WorkOrderStatus;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use DateTimeImmutable;

/**
 * Port of ../web/lib/shop.ts: the service centre's own view — bays, queues,
 * technicians, revenue. Inputs are already scoped by the caller.
 *
 *  - Revenue is recognised on COLLECTION, not completion: a closed but
 *    uncollected job is outstanding.
 *  - Bay time reads the actual duration off the `closed` history event, not
 *    `completedOn` (a date: diffing it against a start instant produced
 *    negative durations for every real job).
 *  - Hours are measures, not money, and stay JavaScript numbers; money is
 *    centavos throughout.
 */
final class Shop
{
    public const string PARTS_MARKUP = '0.22';

    public static function isActiveJob(WorkOrderFacts $order): bool
    {
        return $order->status->isActive();
    }

    public static function startedAt(WorkOrderFacts $order): ?DateTimeImmutable
    {
        return self::firstEvent($order, WorkOrderStatus::InProgress)?->at;
    }

    public static function finishedAt(WorkOrderFacts $order): ?DateTimeImmutable
    {
        $closed = self::firstEvent($order, WorkOrderStatus::Closed);
        if ($closed !== null) {
            return $closed->at;
        }

        return $order->completedOn !== null && $order->completedOn !== '' ? Calendar::parseDate($order->completedOn) : null;
    }

    public static function elapsedMinutes(WorkOrderFacts $order, DateTimeImmutable $now): ?int
    {
        $started = self::startedAt($order);

        return $started === null ? null : max(0, self::minutesBetween($started, $now));
    }

    public static function formatDuration(?int $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;
        if ($hours === 0) {
            return "{$rest}m";
        }

        return $rest === 0 ? "{$hours}h" : "{$hours}h {$rest}m";
    }

    /** The catalogue's estimate for the job's tasks; else its labour at the shop rate; else one hour. */
    public static function estimatedHours(WorkOrderFacts $order, ShopContext $shop): float|int
    {
        $fromCatalogue = 0;
        foreach ($order->taskIds as $id) {
            $fromCatalogue += $shop->taskHours[$id] ?? 0;
        }
        if ($fromCatalogue > 0) {
            return $fromCatalogue;
        }
        if ($order->laborCostCents > 0 && $shop->labourRateCents > 0) {
            return self::number($order->laborCostCents / $shop->labourRateCents);
        }

        return 1;
    }

    /**
     * @param  list<WorkOrderFacts>  $orders
     * @return list<WorkOrderFacts>
     */
    public static function jobsScheduledFor(array $orders, DateTimeImmutable $day): array
    {
        $date = Calendar::toDate($day);

        return array_values(array_filter($orders, fn (WorkOrderFacts $order): bool => $order->status !== WorkOrderStatus::Cancelled
            && $order->status !== WorkOrderStatus::Declined
            && $order->scheduledFor !== null && $order->scheduledFor !== ''
            && Calendar::toDate(Calendar::parseDate($order->scheduledFor)) === $date));
    }

    /**
     * @param  list<WorkOrderFacts>  $orders
     * @return list<BayLoad>
     */
    public static function bayLoadFor(array $orders, DateTimeImmutable $day, ShopContext $shop): array
    {
        $scheduled = self::jobsScheduledFor($orders, $day);

        return array_map(function (BayFacts $bay) use ($scheduled, $shop): BayLoad {
            $jobs = array_values(array_filter($scheduled, fn (WorkOrderFacts $order): bool => $order->bayId === $bay->id));
            $booked = 0;
            foreach ($jobs as $job) {
                $booked += self::estimatedHours($job, $shop);
            }

            return new BayLoad(
                $bay->id,
                $bay->name,
                $booked,
                $bay->capacityHoursPerDay,
                $bay->capacityHoursPerDay > 0 ? self::number($booked / $bay->capacityHoursPerDay) : 0,
                $jobs,
            );
        }, $shop->bays);
    }

    /**
     * @param  list<WorkOrderFacts>  $orders
     */
    public static function floorUtilisation(array $orders, DateTimeImmutable $day, ShopContext $shop): FloorUtilisation
    {
        $loads = self::bayLoadFor($orders, $day, $shop);
        $booked = 0;
        foreach ($loads as $load) {
            $booked += $load->bookedHours;
        }
        $capacity = $shop->capacityHours();

        return new FloorUtilisation($booked, $capacity, $capacity > 0 ? self::number($booked / $capacity) : 0, $loads);
    }

    // ---------------------------------------------------------------- queues

    /**
     * @param  list<WorkOrderFacts>  $orders
     * @return list<WorkOrderFacts>
     */
    public static function arrivingToday(array $orders, DateTimeImmutable $day): array
    {
        $arriving = array_values(array_filter(
            self::jobsScheduledFor($orders, $day),
            fn (WorkOrderFacts $order): bool => $order->status === WorkOrderStatus::Scheduled || $order->status === WorkOrderStatus::Approved,
        ));
        usort($arriving, fn (WorkOrderFacts $a, WorkOrderFacts $b): int => strcmp((string) $a->scheduledFor, (string) $b->scheduledFor));

        return $arriving;
    }

    /**
     * @param  list<WorkOrderFacts>  $orders
     * @return list<WorkOrderFacts>
     */
    public static function inProgress(array $orders): array
    {
        $running = array_values(array_filter($orders, fn (WorkOrderFacts $order): bool => $order->status === WorkOrderStatus::InProgress));
        usort($running, fn (WorkOrderFacts $a, WorkOrderFacts $b): int => self::ms(self::startedAt($a)) <=> self::ms(self::startedAt($b)));

        return $running;
    }

    /**
     * @param  list<WorkOrderFacts>  $orders
     * @return list<WorkOrderFacts>
     */
    public static function readyForCollection(array $orders): array
    {
        $ready = array_values(array_filter($orders, fn (WorkOrderFacts $order): bool => $order->status === WorkOrderStatus::Closed && $order->collectedAt === null));
        usort($ready, fn (WorkOrderFacts $a, WorkOrderFacts $b): int => strcmp($a->completedOn ?? '', $b->completedOn ?? ''));

        return $ready;
    }

    /**
     * @param  list<WorkOrderFacts>  $orders
     */
    public static function awaitingApproval(array $orders, DateTimeImmutable $now): AwaitingApproval
    {
        $pending = array_values(array_filter($orders, fn (WorkOrderFacts $order): bool => $order->status === WorkOrderStatus::PendingApproval));
        $longest = null;
        $total = 0;
        foreach ($pending as $order) {
            $total += Approvals::pendingValue($order->lines);
            if ($order->pendingApprovalEnteredAt === null) {
                continue;
            }
            $hours = BusinessHours::between($order->pendingApprovalEnteredAt, $now);
            if ($longest === null || $hours > $longest->hours) {
                $longest = new WaitingOrder($order, $hours);
            }
        }

        return new AwaitingApproval($pending, count($pending), $total, $longest);
    }

    /**
     * The approval bottleneck: every order waiting on the customer, longest
     * wait (in business hours) first. Orders with no recorded entry time sort
     * last, as zero.
     *
     * @param  list<WorkOrderFacts>  $orders
     * @return list<WaitingOrder>
     */
    public static function approvalBottleneck(array $orders, DateTimeImmutable $now): array
    {
        $waiting = [];
        foreach ($orders as $order) {
            if ($order->status !== WorkOrderStatus::PendingApproval) {
                continue;
            }
            $waiting[] = new WaitingOrder($order, $order->pendingApprovalEnteredAt === null ? 0 : BusinessHours::between($order->pendingApprovalEnteredAt, $now));
        }
        usort($waiting, fn (WaitingOrder $a, WaitingOrder $b): int => $b->hours <=> $a->hours);

        return $waiting;
    }

    // --------------------------------------------------------------- revenue

    /**
     * Recognised on collection, in [from, to).
     *
     * @param  list<WorkOrderFacts>  $orders
     */
    public static function revenueBetween(array $orders, DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $total = 0;
        foreach ($orders as $order) {
            if (self::collectedWithin($order, $from, $to)) {
                $total += $order->costCents();
            }
        }

        return $total;
    }

    /**
     * @param  list<AccountRef>  $accounts
     * @param  array<string, int>  $vehicleCounts  account id → vehicles
     * @param  list<WorkOrderFacts>  $orders
     * @return list<AccountRollup>
     */
    public static function rollupAccounts(array $accounts, array $vehicleCounts, array $orders, DateTimeImmutable $periodStart, DateTimeImmutable $periodEnd): array
    {
        return array_map(function (AccountRef $account) use ($vehicleCounts, $orders, $periodStart, $periodEnd): AccountRollup {
            $own = self::ownedBy($orders, $account->id);
            $outstanding = 0;
            foreach ($own as $order) {
                if ($order->status === WorkOrderStatus::Closed && $order->collectedAt === null) {
                    $outstanding += $order->costCents();
                }
            }

            return new AccountRollup(
                $account,
                $vehicleCounts[$account->id] ?? 0,
                count(array_filter($own, self::isActiveJob(...))),
                self::meanTenth(self::waits($own)),
                self::revenueBetween($own, $periodStart, $periodEnd),
                $outstanding,
            );
        }, $accounts);
    }

    /**
     * @param  list<string>  $technicians
     * @param  list<WorkOrderFacts>  $orders
     * @return list<TechnicianLoad>
     */
    public static function technicianLoad(array $technicians, array $orders, DateTimeImmutable $periodStart, DateTimeImmutable $periodEnd, ShopContext $shop): array
    {
        return array_map(function (string $technician) use ($orders, $periodStart, $periodEnd, $shop): TechnicianLoad {
            $own = array_values(array_filter($orders, fn (WorkOrderFacts $order): bool => $order->technician === $technician));
            $completed = array_values(array_filter($own, function (WorkOrderFacts $order) use ($periodStart, $periodEnd): bool {
                if ($order->status !== WorkOrderStatus::Closed || $order->completedOn === null || $order->completedOn === '') {
                    return false;
                }
                $done = Calendar::parseDate($order->completedOn);

                return $done >= $periodStart && $done < $periodEnd;
            }));

            $actual = [];
            $estimate = [];
            foreach ($completed as $order) {
                $started = self::startedAt($order);
                $finished = self::finishedAt($order);
                if ($started === null || $finished === null) {
                    continue;
                }
                $hours = self::number(self::minutesBetween($started, $finished) / 60);
                if ($hours > 0) {
                    $actual[] = $hours;
                    $estimate[] = self::estimatedHours($order, $shop);
                }
            }

            $current = null;
            foreach ($own as $order) {
                if ($order->status === WorkOrderStatus::InProgress) {
                    $current = $order;
                    break;
                }
            }

            return new TechnicianLoad($technician, $current, count($completed), self::meanTenth($actual), self::meanTenth($estimate));
        }, $technicians);
    }

    // ------------------------------------------------------------- reporting

    /**
     * @param  list<AccountRef>  $accounts
     * @param  list<WorkOrderFacts>  $orders
     * @return list<NamedValue> value in centavos, highest first, zero rows dropped
     */
    public static function revenueByAccount(array $accounts, array $orders, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = [];
        foreach ($accounts as $account) {
            $value = self::revenueBetween(self::ownedBy($orders, $account->id), $from, $to);
            if ($value > 0) {
                $rows[] = new NamedValue($account->name, $value);
            }
        }
        usort($rows, fn (NamedValue $a, NamedValue $b): int => $b->value <=> $a->value);

        return $rows;
    }

    /**
     * A multi-item job's revenue split evenly across its service items, each
     * item's total rounded to the whole peso (a reporting cut, as ../web).
     *
     * @param  list<WorkOrderFacts>  $orders
     * @return list<NamedValue> value in centavos
     */
    public static function revenueByServiceItem(array $orders, DateTimeImmutable $from, DateTimeImmutable $to, ShopContext $shop): array
    {
        /** @var array<string, BigRational> $totals */
        $totals = [];
        foreach ($orders as $order) {
            if (! self::collectedWithin($order, $from, $to)) {
                continue;
            }
            $ids = $order->taskIds === [] ? ['__other'] : $order->taskIds;
            $share = BigRational::of($order->costCents())->dividedBy(count($ids));
            foreach ($ids as $id) {
                $name = $id === '__other' ? 'Corrective & other' : ($shop->taskNames[$id] ?? $id);
                $totals[$name] = ($totals[$name] ?? BigRational::zero())->plus($share);
            }
        }

        $rows = [];
        foreach ($totals as $name => $cents) {
            $rows[] = new NamedValue((string) $name, $cents->dividedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt() * 100);
        }
        usort($rows, fn (NamedValue $a, NamedValue $b): int => $b->value <=> $a->value);

        return $rows;
    }

    /**
     * @param  list<WorkOrderFacts>  $orders
     * @return list<UtilisationPoint> oldest first
     */
    public static function utilisationSeries(array $orders, int $days, DateTimeImmutable $now, ShopContext $shop): array
    {
        $points = [];
        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $day = Calendar::startOfDay(Calendar::local($now)->modify(sprintf('-%d seconds', $offset * 86_400)));
            $floor = self::floorUtilisation($orders, $day, $shop);
            $points[] = new UtilisationPoint(
                Calendar::toDate($day),
                $day->format('j/n'),
                JsMath::round($floor->utilisation * 100),
                JsMath::round($floor->bookedHours * 10) / 10,
            );
        }

        return $points;
    }

    /**
     * @param  list<AccountRef>  $accounts
     * @param  list<WorkOrderFacts>  $orders
     * @return list<NamedValue> value in hours
     */
    public static function approvalTurnaroundByAccount(array $accounts, array $orders): array
    {
        $rows = [];
        foreach ($accounts as $account) {
            $waits = self::waits(self::ownedBy($orders, $account->id));
            $value = self::meanTenth($waits) ?? 0;
            if ($value > 0) {
                $rows[] = new NamedValue($account->name, $value, count($waits).' decided');
            }
        }
        usort($rows, fn (NamedValue $a, NamedValue $b): int => $b->value <=> $a->value);

        return $rows;
    }

    /**
     * Parts value by who supplied it, on closed work; the shop's margin is
     * the markup inside what it bought in: supplied × 0.22 / 1.22.
     *
     * @param  list<WorkOrderFacts>  $orders
     */
    public static function partsMargin(array $orders): PartsMargin
    {
        $supplier = 0;
        $own = 0;
        foreach ($orders as $order) {
            if ($order->status !== WorkOrderStatus::Closed) {
                continue;
            }
            if ($order->lines === []) {
                $supplier += $order->partsCents();

                continue;
            }
            foreach ($order->lines as $line) {
                if ($line->approvalStatus !== LineApprovalStatus::Approved) {
                    continue;
                }
                if ($line->partsSource === PartsSource::OwnStock) {
                    $own += $line->partCostCents;
                } else {
                    $supplier += $line->partCostCents;
                }
            }
        }

        $markup = BigRational::of(self::PARTS_MARKUP);
        $margin = BigRational::of($supplier)->multipliedBy($markup)->dividedBy($markup->plus(1));

        return new PartsMargin($supplier, $own, $margin->toScale(0, RoundingMode::HalfUp)->toInt());
    }

    /**
     * @return list<PmsItem>
     */
    public static function serviceableItems(?VehicleHealth $health): array
    {
        return $health === null ? [] : array_values(array_filter($health->items, fn (PmsItem $item): bool => $item->status !== 'ok'));
    }

    /** What the customer authorised: the approved lines, or the job total when it has none. */
    public static function authorisedValue(WorkOrderFacts $order): int
    {
        return $order->lines === [] ? $order->costCents() : Approvals::approvedValue($order->lines);
    }

    public static function today(DateTimeImmutable $now): DateTimeImmutable
    {
        return Calendar::startOfDay($now);
    }

    // --------------------------------------------------------------- helpers

    private static function firstEvent(WorkOrderFacts $order, WorkOrderStatus $status): ?StatusEvent
    {
        foreach ($order->history as $event) {
            if ($event->status === $status) {
                return $event;
            }
        }

        return null;
    }

    private static function collectedWithin(WorkOrderFacts $order, DateTimeImmutable $from, DateTimeImmutable $to): bool
    {
        return $order->collectedAt !== null && $order->collectedAt >= $from && $order->collectedAt < $to;
    }

    /**
     * @param  list<WorkOrderFacts>  $orders
     * @return list<WorkOrderFacts>
     */
    private static function ownedBy(array $orders, string $accountId): array
    {
        return array_values(array_filter($orders, fn (WorkOrderFacts $order): bool => $order->customerAccountId === $accountId));
    }

    /**
     * @param  list<WorkOrderFacts>  $orders
     * @return list<float|int>
     */
    private static function waits(array $orders): array
    {
        $waits = [];
        foreach ($orders as $order) {
            if ($order->approvalWaitHours !== null) {
                $waits[] = $order->approvalWaitHours;
            }
        }

        return $waits;
    }

    /**
     * @param  list<float|int>  $values
     */
    private static function meanTenth(array $values): float|int|null
    {
        if ($values === []) {
            return null;
        }
        $sum = 0;
        foreach ($values as $value) {
            $sum += $value;
        }

        return JsMath::round($sum / count($values) * 10) / 10;
    }

    /** date-fns differenceInMinutes: whole minutes, truncated toward zero. */
    private static function minutesBetween(DateTimeImmutable $earlier, DateTimeImmutable $later): int
    {
        $ms = self::ms($later) - self::ms($earlier);

        return intdiv($ms, 60_000);
    }

    private static function ms(?DateTimeImmutable $instant): int
    {
        return $instant === null ? 0 : (int) $instant->format('Uv');
    }

    /** A JavaScript number: integral floats as ints, so they compare like JS's one number type. */
    private static function number(float|int $value): float|int
    {
        return is_float($value) && floor($value) === $value && abs($value) < 1e15 ? (int) $value : $value;
    }
}

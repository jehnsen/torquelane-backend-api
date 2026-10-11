<?php

declare(strict_types=1);

namespace App\Actions\Detailing;

use App\Database\Cell;
use App\Domain\Detailing\Availability;
use App\Domain\Detailing\BookingStatus;
use App\Domain\Detailing\DayPlanner;
use App\Domain\Detailing\PlannedDay;
use App\Domain\Detailing\ServiceDuration;
use App\Domain\Detailing\SizeClass;
use App\Domain\Detailing\SlotGrid;
use App\Domain\Shared\Calendar;
use App\Models\Bay;
use App\Models\Branch;
use App\Models\DetailingAddon;
use App\Models\DetailingBlockedSlot;
use App\Models\DetailingBooking;
use App\Models\DetailingBookingSlot;
use App\Models\DetailingCategory;
use App\Models\DetailingPackage;
use App\Models\Technician;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Detailing reads. Everything a screen shows that depends on a rule is
 * answered here, so the frontend derives none of it (R2): which starts are
 * free and why not, where a job would sit, when it should finish, how many
 * detailers a slot has left, what counts a list has.
 */
final class DetailingQueries
{
    /** What a booking is shown with. */
    public const array WITH = ['detailers.technician', 'addons', 'slots', 'bay', 'invoiceLinks.invoice', 'inspections', 'prepaidRedemption.package'];

    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly DetailingBranches $branches,
        private readonly DetailingJournal $journal,
    ) {}

    /**
     * @param  array{branch_id?: string|null, status?: list<string>, date_from?: string|null, date_to?: string|null, q?: string|null, customer_account_id?: string|null}  $filters
     * @return LengthAwarePaginator<int, DetailingBooking>
     */
    public function bookings(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->filtered($filters)
            ->with(self::WITH)
            ->orderByDesc('scheduled_date')
            ->orderByDesc('start_minutes')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * How many bookings there are in each status under the same filters (the
     * status filter aside), and what today holds.
     *
     * @param  array{branch_id?: string|null, date_from?: string|null, date_to?: string|null, q?: string|null, customer_account_id?: string|null}  $filters
     * @return array{total: int, by_status: array<string, int>, today: array{date: string, bookings: int, on_the_floor: int, pending: int}}
     */
    public function summary(array $filters): array
    {
        $byStatus = array_fill_keys(BookingStatus::values(), 0);
        foreach ($this->filtered($filters)->selectRaw('status, count(*) as bookings')->groupBy('status')->toBase()->get() as $row) {
            $byStatus[Cell::string($row->status)] = Cell::int($row->bookings);
        }

        $today = Calendar::toDate(CarbonImmutable::now());
        $todays = $this->filtered(['branch_id' => $filters['branch_id'] ?? null])->whereDate('scheduled_date', $today)->get(['id', 'status']);

        return [
            'total' => array_sum($byStatus),
            'by_status' => $byStatus,
            'today' => [
                'date' => $today,
                'bookings' => $todays->count(),
                'on_the_floor' => $todays->filter(fn (DetailingBooking $booking): bool => $booking->status->isOnTheFloor())->count(),
                'pending' => $todays->filter(fn (DetailingBooking $booking): bool => $booking->status === BookingStatus::Pending)->count(),
            ],
        ];
    }

    /**
     * A branch's menu: categories, packages with their prices and materials, add-ons.
     *
     * @return array{categories: Collection<int, DetailingCategory>, packages: Collection<int, DetailingPackage>, addons: Collection<int, DetailingAddon>}
     */
    public function catalogue(Branch $branch, bool $includeInactive): array
    {
        return [
            'categories' => DetailingCategory::query()->where('branch_id', $branch->id)->orderBy('position')->orderBy('name')->get(),
            'packages' => DetailingPackage::query()->where('branch_id', $branch->id)
                ->when(! $includeInactive, fn (Builder $query) => $query->where('is_active', true))
                ->with(['prices', 'materials.item', 'category'])->orderBy('position')->orderBy('name')->get(),
            'addons' => DetailingAddon::query()->where('branch_id', $branch->id)
                ->when(! $includeInactive, fn (Builder $query) => $query->where('is_active', true))
                ->with('prices')->orderBy('position')->orderBy('name')->get(),
        ];
    }

    /**
     * The branch's detailers: active technicians who detail.
     *
     * @return list<Technician>
     */
    public function detailers(Branch $branch): array
    {
        return array_values(Technician::query()->where('branch_id', $branch->id)->where('status', 'active')->orderBy('name')->get()
            ->filter(fn (Technician $technician): bool => in_array('detailer', $technician->skill_tags, true))->all());
    }

    /**
     * Every grid slot of a day as a possible start for a package at a size:
     * whether it is free, why not, how many detailers the tightest slot has
     * left, where the job would sit and when it would end, and which
     * detailers are already busy in those hours.
     *
     * @return array{date: string, package_id: string, size_class: string, duration_text: string, slot_budget: int, min_detailers: int, pool: int, closing_minutes: int, starts: list<array<string, mixed>>}
     */
    public function availability(Branch $branch, DetailingPackage $package, SizeClass $size, string $date, ?string $exceptBookingId = null, ?int $onlyStart = null): array
    {
        $settings = $this->journal->settings($branch->organization_id, $branch->id);
        $duration = $package->loadMissing('prices')->durationFor($size);
        $budget = ServiceDuration::slotBudget($duration);

        $plans = [];
        $dates = [];
        foreach ($onlyStart === null ? SlotGrid::slots() : [$onlyStart] as $start) {
            $plans[$start] = DayPlanner::plan($date, $start, $budget, $settings->closing_minutes);
            array_push($dates, ...$plans[$start]->dates());
        }
        $dates = array_values(array_unique($dates));

        $occupancy = $this->journal->occupancy($branch->id, $dates, $exceptBookingId);
        $blocked = $this->blockedKeys($branch, $dates);
        $detailerIds = array_map(fn (Technician $technician): string => $technician->id, $this->detailers($branch));

        $starts = [];
        foreach ($plans as $start => $plan) {
            $answer = Availability::of($plan, $occupancy, $blocked, $settings->detailer_pool_size, $package->min_detailers);
            $starts[] = [
                'start_minutes' => $start,
                'time' => SlotGrid::time($start),
                'label' => SlotGrid::label($start),
                'slot' => SlotGrid::nearest($start),
                ...$answer,
                'spans_days' => $plan->spansDays(),
                'plan' => self::planArray($plan->days),
                'ends' => $plan->lastDate() === null || $plan->endMinutes() === null ? null : [
                    'date' => $plan->lastDate(),
                    'minutes' => $plan->endMinutes(),
                    'label' => SlotGrid::label($plan->endMinutes()),
                ],
                'busy_detailer_ids' => $occupancy->conflicts($plan->cells(), $detailerIds),
            ];
        }

        return [
            'date' => $date,
            'package_id' => $package->id,
            'size_class' => $size->value,
            'duration_text' => $duration,
            'slot_budget' => $budget,
            'min_detailers' => $package->min_detailers,
            'pool' => $settings->detailer_pool_size,
            'closing_minutes' => $settings->closing_minutes,
            'starts' => $starts,
        ];
    }

    /**
     * The schedule of a branch over a span of days: every booking that sits
     * on one of them (by its slots, or by the day asked for), the slots the
     * shop has blocked, and how many heads each slot holds against the pool.
     *
     * @return array{branch_id: string, from: string, to: string, pool: int, closing_minutes: int, grid: list<array{slot: int, time: string, label: string, after_closing: bool}>, days: list<array{date: string, slots: list<array{slot: int, held: int, remaining: int}>}>, bays: list<array{id: string, name: string, focus: string|null, status: string}>, detailers: list<array{id: string, name: string}>, blocked: list<array{id: string, date: string, slot: int, label: string}>, bookings: Collection<int, DetailingBooking>}
     */
    public function schedule(Branch $branch, string $from, string $to): array
    {
        $settings = $this->journal->settings($branch->organization_id, $branch->id);
        $bookings = $this->onDates($branch, $from, $to);

        $held = [];
        foreach ($bookings as $booking) {
            if (! $booking->status->holdsCapacity()) {
                continue;
            }
            foreach ($booking->slots as $slot) {
                $date = $slot->date->toDateString();
                if (! $slot->released && $date >= $from && $date <= $to) {
                    $held[$date][$slot->slot] = ($held[$date][$slot->slot] ?? 0) + $booking->detailers_count;
                }
            }
        }
        $blocked = DetailingBlockedSlot::query()->where('branch_id', $branch->id)->whereBetween('date', [$from, $to])->orderBy('date')->orderBy('slot')->get();

        $days = [];
        for ($day = Calendar::parseDate($from); Calendar::toDate($day) <= $to; $day = Calendar::addDays($day, 1)) {
            $date = Calendar::toDate($day);
            $days[] = [
                'date' => $date,
                'slots' => array_map(fn (int $slot): array => [
                    'slot' => $slot,
                    'held' => $held[$date][$slot] ?? 0,
                    'remaining' => $settings->detailer_pool_size - ($held[$date][$slot] ?? 0),
                ], SlotGrid::slots()),
            ];
        }

        return [
            'branch_id' => $branch->id,
            'from' => $from,
            'to' => $to,
            'pool' => $settings->detailer_pool_size,
            'closing_minutes' => $settings->closing_minutes,
            'grid' => array_map(fn (int $slot): array => ['slot' => $slot, 'time' => SlotGrid::time($slot), 'label' => SlotGrid::label($slot), 'after_closing' => $slot >= $settings->closing_minutes], SlotGrid::slots()),
            'days' => $days,
            'bays' => array_values(Bay::query()->where('branch_id', $branch->id)->orderBy('name')->get()
                ->map(fn (Bay $bay): array => ['id' => $bay->id, 'name' => $bay->name, 'focus' => $bay->focus, 'status' => $bay->status])->all()),
            'detailers' => array_map(fn (Technician $technician): array => ['id' => $technician->id, 'name' => $technician->name], $this->detailers($branch)),
            'blocked' => array_values($blocked->map(fn (DetailingBlockedSlot $slot): array => [
                'id' => $slot->id,
                'date' => $slot->date->toDateString(),
                'slot' => $slot->slot,
                'label' => $slot->label,
            ])->all()),
            'bookings' => $bookings,
        ];
    }

    /**
     * The shop monitor: the confirmed, on-going and completed bookings that
     * sit on a day, as the wall screen shows them.
     *
     * @return array{branch_id: string, date: string, generated_at: string, counts: array{upcoming: int, in_progress: int, done: int}, jobs: list<array<string, mixed>>}
     */
    public function monitor(Branch $branch, string $date): array
    {
        $jobs = [];
        $counts = ['upcoming' => 0, 'in_progress' => 0, 'done' => 0];
        foreach ($this->onDates($branch, $date, $date) as $booking) {
            if (! $booking->status->isOnTheFloor()) {
                continue;
            }
            $stage = match ($booking->status) {
                BookingStatus::OnGoing => 'in_progress',
                BookingStatus::Completed => 'done',
                default => 'upcoming',
            };
            $counts[$stage]++;
            $jobs[] = self::monitorJob($booking, $date, $stage);
        }
        usort($jobs, fn (array $a, array $b): int => [$a['start_minutes'], $a['reference']] <=> [$b['start_minutes'], $b['reference']]);

        return [
            'branch_id' => $branch->id,
            'date' => $date,
            'generated_at' => CarbonImmutable::now()->utc()->toIso8601ZuluString(),
            'counts' => $counts,
            'jobs' => $jobs,
        ];
    }

    /**
     * When a booking is expected to finish, or did: the source's
     * `computeBookingETC`. A completed job shows when it really ended.
     *
     * @return array{date: string|null, minutes: int|null, label: string|null, actual: bool}
     */
    public static function finish(DetailingBooking $booking): array
    {
        if ($booking->status === BookingStatus::Completed && $booking->completed_at !== null) {
            $local = Calendar::local($booking->completed_at);
            $minutes = (int) $local->format('G') * 60 + (int) $local->format('i');

            return ['date' => $local->format('Y-m-d'), 'minutes' => $minutes, 'label' => SlotGrid::label($minutes), 'actual' => true];
        }
        $last = $booking->slots->last();
        if (! $last instanceof DetailingBookingSlot) {
            return ['date' => null, 'minutes' => null, 'label' => null, 'actual' => false];
        }
        $minutes = $last->slot + SlotGrid::SLOT_MINUTES;

        return ['date' => $last->date->toDateString(), 'minutes' => $minutes, 'label' => SlotGrid::label($minutes), 'actual' => false];
    }

    /**
     * A booking's slots, a day at a time.
     *
     * @return list<array{date: string, slots: list<array{slot: int, label: string, released: bool}>}>
     */
    public static function days(DetailingBooking $booking): array
    {
        $days = [];
        foreach ($booking->slots as $slot) {
            $days[$slot->date->toDateString()][] = ['slot' => $slot->slot, 'label' => SlotGrid::label($slot->slot), 'released' => $slot->released];
        }

        return array_map(fn (string $date, array $slots): array => ['date' => $date, 'slots' => $slots], array_keys($days), array_values($days));
    }

    /**
     * @param  list<PlannedDay>  $days
     * @return list<array{date: string, slots: list<int>}>
     */
    private static function planArray(array $days): array
    {
        return array_map(fn (PlannedDay $day): array => ['date' => $day->date, 'slots' => $day->slots], $days);
    }

    /**
     * @return array<string, mixed>
     */
    private static function monitorJob(DetailingBooking $booking, string $date, string $stage): array
    {
        $today = array_values($booking->slots->filter(fn (DetailingBookingSlot $slot): bool => $slot->date->toDateString() === $date && ! $slot->released)->all());
        $first = $booking->slots->first();
        $done = count(array_filter($booking->checklist, fn (array $line): bool => $line['done']));

        return [
            'id' => $booking->id,
            'reference' => $booking->reference,
            'status' => $booking->status->value,
            'stage' => $stage,
            'customer_name' => $booking->customer_name,
            'vehicle_label' => $booking->vehicle_label,
            'size_class' => $booking->size_class->value,
            'package_name' => $booking->package_name,
            'bay_name' => $booking->bay?->name,
            'detailers' => array_values($booking->detailers->map(fn ($row): string => $row->technician->name)->all()),
            'detailers_count' => $booking->detailers_count,
            // Where it sits on THIS day (a continuation day starts at opening), for sorting and the clock.
            'start_minutes' => $today !== [] ? $today[0]->slot : $booking->start_minutes,
            'start_label' => SlotGrid::label($today !== [] ? $today[0]->slot : $booking->start_minutes),
            'continues_from' => $first instanceof DetailingBookingSlot && $first->date->toDateString() < $date ? $first->date->toDateString() : null,
            'finish' => self::finish($booking),
            'started_at' => $booking->started_at?->utc()->toIso8601ZuluString(),
            'completed_at' => $booking->completed_at?->utc()->toIso8601ZuluString(),
            'checklist' => ['done' => $done, 'total' => count($booking->checklist)],
            'qc_status' => $booking->qc_status,
        ];
    }

    /**
     * Bookings that sit on a span of days: one of their slots falls in it, or
     * the day they were asked for does.
     *
     * @return Collection<int, DetailingBooking>
     */
    private function onDates(Branch $branch, string $from, string $to): Collection
    {
        return DetailingBooking::query()
            ->where('branch_id', $branch->id)
            ->where(function (Builder $query) use ($branch, $from, $to): void {
                $query->whereBetween('scheduled_date', [$from, $to])
                    ->orWhereIn('id', DetailingBookingSlot::query()->where('branch_id', $branch->id)->whereBetween('date', [$from, $to])->select('booking_id'));
            })
            ->with(self::WITH)
            ->orderBy('scheduled_date')->orderBy('start_minutes')->orderBy('id')
            ->get();
    }

    /**
     * @param  list<string>  $dates
     * @return array<string, true>
     */
    private function blockedKeys(Branch $branch, array $dates): array
    {
        $keys = [];
        if ($dates === []) {
            return $keys;
        }
        foreach (DetailingBlockedSlot::query()->where('branch_id', $branch->id)->whereIn('date', $dates)->get(['date', 'slot']) as $row) {
            $keys[$row->date->toDateString().'|'.$row->slot] = true;
        }

        return $keys;
    }

    /**
     * @param  array{branch_id?: string|null, status?: list<string>, date_from?: string|null, date_to?: string|null, q?: string|null, customer_account_id?: string|null}  $filters
     * @return Builder<DetailingBooking>
     */
    private function filtered(array $filters): Builder
    {
        $context = $this->tenancy->require();
        $branchIds = $this->branches->reachable();
        if (($filters['branch_id'] ?? null) !== null) {
            $branchIds = array_values(array_intersect($branchIds, [$filters['branch_id']]));
        }

        $query = DetailingBooking::query()->visibleTo($context)->whereIn('branch_id', $branchIds);
        if (($filters['status'] ?? []) !== []) {
            $query->whereIn('status', $filters['status']);
        }
        if (($filters['date_from'] ?? null) !== null) {
            $query->whereDate('scheduled_date', '>=', $filters['date_from']);
        }
        if (($filters['date_to'] ?? null) !== null) {
            $query->whereDate('scheduled_date', '<=', $filters['date_to']);
        }
        if (($filters['customer_account_id'] ?? null) !== null) {
            $query->where('customer_account_id', $filters['customer_account_id']);
        }
        $q = trim($filters['q'] ?? '');
        if ($q !== '') {
            $like = '%'.addcslashes(mb_strtolower($q), '%_\\').'%';
            $query->where(function (Builder $inner) use ($like): void {
                $inner->whereRaw('lower(reference) like ?', [$like])
                    ->orWhereRaw('lower(customer_name) like ?', [$like])
                    ->orWhereRaw('lower(coalesce(vehicle_label, \'\')) like ?', [$like])
                    ->orWhereRaw('lower(coalesce(customer_phone, \'\')) like ?', [$like]);
            });
        }

        return $query;
    }
}

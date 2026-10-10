<?php

declare(strict_types=1);

use App\Domain\Detailing\BookingCapacity;
use App\Domain\Detailing\BookingClock;
use App\Domain\Detailing\BookingStatus;
use App\Domain\Detailing\DayPlanner;
use App\Domain\Detailing\Occupancy;
use App\Domain\Detailing\PlannedDay;
use App\Domain\Detailing\ServiceDuration;
use App\Domain\Detailing\SlotRelease;
use App\Domain\Shared\Calendar;

/*
 * Characterization: the source's own SQL (add_booking, update_booking_detailers,
 * update_booking_status), recorded step by step in a scratch database. Here the
 * recording is replayed through the pure rules with the bookings held in
 * memory; tests/Feature/Detailing/EngineCharacterizationTest replays the same
 * recording through the Actions and the database.
 */

/**
 * @return array{detailers: array<string, string>, scenarios: list<array{name: string, pool: int, closing_minutes: int, steps: list<array<string, mixed>>}>}
 */
function engineFixture(): array
{
    static $fixture = null;

    return $fixture ??= json_decode((string) file_get_contents(__DIR__.'/../../../Golden/fixtures/detailing/engine.json'), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * The bookings of one scenario, in memory, moved only by the domain's rules.
 */
final class EngineMemory
{
    /** @var array<string, array{status: BookingStatus, heads: int, named: list<string>, slots: list<array{date: string, slot: int, released: bool}>, started: ?DateTimeImmutable, completed: ?DateTimeImmutable, reason: ?string}> */
    public array $bookings = [];

    /** @var list<array{key: string, from: ?string, to: string, notes: ?string}> */
    public array $logs = [];

    /**
     * @param  array<string, string>  $names
     */
    public function __construct(private readonly int $pool, private readonly int $closing, private readonly array $names) {}

    /**
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    public function book(array $step): array
    {
        $plan = DayPlanner::plan($step['date'], $step['start_minutes'], ServiceDuration::slotBudget($step['duration']), $this->closing);
        expect(array_map(fn (PlannedDay $day): array => ['date' => $day->date, 'slots' => $day->slots], $plan->days))->toBe($step['source_plan']);

        $min = $step['min'] ?? 1;
        $requested = isset($step['requested']) && $step['requested'] > 0 ? $step['requested'] : null;
        $admission = BookingCapacity::admit($plan, $this->occupancy(null), $this->pool, $min, $requested, $step['named'] ?? []);
        if (! $admission->isAdmitted()) {
            return ['ok' => false, 'error' => $admission->message($this->names, $min)];
        }

        $status = BookingStatus::fromSource($step['status'] ?? 'pending') ?? BookingStatus::Pending;
        $this->bookings[$step['key']] = [
            'status' => $status,
            'heads' => $admission->headcount,
            'named' => $admission->detailerIds,
            'slots' => array_map(fn (array $cell): array => $cell + ['released' => false], $plan->cells()),
            'started' => null,
            'completed' => null,
            'reason' => null,
        ];

        return ['ok' => true, 'status' => $status->sourceValue(), 'heads' => $admission->headcount, 'named' => $admission->detailerIds];
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    public function status(array $step): array
    {
        $to = BookingStatus::fromSource($step['to']);
        if ($to === null) {
            return ['ok' => false, 'error' => 'Invalid status.'];
        }
        if (! isset($this->bookings[$step['key']])) {
            return ['ok' => false, 'error' => 'Booking not found.'];
        }
        $booking = $this->bookings[$step['key']];
        $now = new DateTimeImmutable($step['at']);
        $from = $booking['status'];

        $stamps = BookingClock::stamp($to, $booking['started'], $booking['completed'], $now);
        $booking['status'] = $to;
        $booking['started'] = $stamps['started_at'];
        $booking['completed'] = $stamps['completed_at'];
        if ($to === BookingStatus::Cancelled) {
            $booking['reason'] = $step['reason'] ?? null;
        }

        if ($to === BookingStatus::Completed) {
            $local = Calendar::local($now);
            $released = SlotRelease::onCompletion(
                array_map(fn (array $slot): array => ['date' => $slot['date'], 'slot' => $slot['slot']], $booking['slots']),
                $local->format('Y-m-d'),
                (int) $local->format('G') * 60 + (int) $local->format('i'),
            );
            foreach ($booking['slots'] as $index => $slot) {
                if (in_array(['date' => $slot['date'], 'slot' => $slot['slot']], $released, true)) {
                    $booking['slots'][$index]['released'] = true;
                }
            }
        } elseif ($to->reopens()) {
            foreach ($booking['slots'] as $index => $slot) {
                $booking['slots'][$index]['released'] = false;
            }
        }

        $this->bookings[$step['key']] = $booking;
        $this->logs[] = ['key' => $step['key'], 'from' => $from->sourceValue(), 'to' => $to->sourceValue(), 'notes' => $step['reason'] ?? null];

        $iso = fn (?DateTimeImmutable $at): ?string => $at?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');

        return [
            'ok' => true,
            'status' => $to->sourceValue(),
            'started_at' => $iso($booking['started']),
            'completed_at' => $iso($booking['completed']),
            'cancellation_reason' => $booking['reason'],
        ];
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    public function detailers(array $step): array
    {
        $named = $step['named'] ?? [];
        $min = $step['min'] ?? 1;
        if ($named !== [] && ! isset($this->bookings[$step['key']])) {
            return ['ok' => false, 'error' => 'Booking not found.'];
        }
        $span = array_map(
            fn (array $slot): array => ['date' => $slot['date'], 'slot' => $slot['slot']],
            $this->bookings[$step['key']]['slots'] ?? [],
        );
        $admission = BookingCapacity::reassign($span, $this->occupancy($step['key']), $this->pool, $min, $named);
        if (! $admission->isAdmitted()) {
            return ['ok' => false, 'error' => $admission->message($this->names, $min, reassigning: true)];
        }
        $this->bookings[$step['key']]['heads'] = $admission->headcount;
        $this->bookings[$step['key']]['named'] = $admission->detailerIds;

        return ['ok' => true, 'heads' => $admission->headcount, 'named' => $admission->detailerIds];
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $bookings = [];
        $slots = [];
        $held = [];
        foreach ($this->bookings as $key => $booking) {
            $bookings[] = ['key' => $key, 'named' => $booking['named'], 'heads' => $booking['heads'], 'status' => $booking['status']->sourceValue()];
            foreach ($booking['slots'] as $slot) {
                $slots[] = ['key' => $key, 'date' => $slot['date'], 'slot' => $slot['slot'], 'released' => $slot['released']];
                if (! $slot['released'] && $booking['status']->holdsCapacity()) {
                    $at = $slot['date'].'|'.str_pad((string) $slot['slot'], 5, '0', STR_PAD_LEFT);
                    $held[$at] = ($held[$at] ?? 0) + $booking['heads'];
                }
            }
        }
        ksort($held);

        return engineNormalize([
            'bookings' => $bookings,
            'slots' => $slots,
            'logs' => $this->logs,
            'held' => array_map(
                fn (string $at, int $heads): array => ['date' => explode('|', $at)[0], 'slot' => (int) explode('|', $at)[1], 'heads' => $heads],
                array_keys($held),
                array_values($held),
            ),
        ]);
    }

    private function occupancy(?string $except): Occupancy
    {
        $rows = [];
        foreach ($this->bookings as $key => $booking) {
            if ($key === $except || ! $booking['status']->holdsCapacity()) {
                continue;
            }
            foreach ($booking['slots'] as $slot) {
                if (! $slot['released']) {
                    $rows[] = ['date' => $slot['date'], 'slot' => $slot['slot'], 'heads' => $booking['heads'], 'detailer_ids' => $booking['named']];
                }
            }
        }

        return Occupancy::of($rows);
    }
}

/**
 * A snapshot in one order and one key order, whichever side produced it
 * (Postgres sorts keys by its collation, PHP by bytes).
 *
 * @param  array<string, mixed>  $snapshot
 * @return array<string, mixed>
 */
function engineNormalize(array $snapshot): array
{
    $sorted = function (array $rows, array $by): array {
        $rows = array_map(function (array $row): array {
            ksort($row);

            return $row;
        }, $rows);
        usort($rows, function (array $a, array $b) use ($by): int {
            foreach ($by as $field) {
                if ($a[$field] !== $b[$field]) {
                    return $a[$field] <=> $b[$field];
                }
            }

            return 0;
        });

        return $rows;
    };

    return [
        'bookings' => $sorted($snapshot['bookings'], ['key']),
        'slots' => $sorted($snapshot['slots'], ['key', 'date', 'slot']),
        // The log is in the order it was written: that order is the point.
        'logs' => array_map(function (array $row): array {
            ksort($row);

            return $row;
        }, $snapshot['logs']),
        'held' => $sorted($snapshot['held'], ['date', 'slot']),
    ];
}

it('follows the source', function (array $scenario) {
    $memory = new EngineMemory($scenario['pool'], $scenario['closing_minutes'], engineFixture()['detailers']);

    foreach ($scenario['steps'] as $index => $step) {
        $actual = match ($step['op']) {
            'book' => $memory->book($step),
            'status' => $memory->status($step),
            'detailers' => $memory->detailers($step),
            default => $memory->snapshot(),
        };
        $expected = $step['op'] === 'snapshot' ? engineNormalize($step['expect']) : $step['expect'];
        ksort($actual);
        ksort($expected);

        expect($actual)->toBe($expected, sprintf('step %d (%s %s)', $index + 1, $step['op'], $step['key'] ?? ''));
    }
})->with(fn (): array => array_column(
    array_map(fn (array $scenario): array => ['name' => $scenario['name'], 'data' => [$scenario]], engineFixture()['scenarios']),
    'data',
    'name',
));

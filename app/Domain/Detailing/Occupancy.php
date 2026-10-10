<?php

declare(strict_types=1);

namespace App\Domain\Detailing;

/**
 * Who already holds each (date, slot): the heads reserved there and the
 * detailers named there, over every booking that still holds capacity. Built
 * from `booking_day_slots` rows that are not released, of bookings that are
 * neither cancelled nor no-show.
 *
 * Heads are each booking's `detailers_count`, never the number of names: a
 * booking with nobody named still reserves its heads.
 */
final class Occupancy
{
    /** @var array<string, int> */
    private array $heads = [];

    /** @var array<string, array<string, true>> */
    private array $busy = [];

    /**
     * @param  list<array{date: string, slot: int, heads: int, detailer_ids: list<string>}>  $rows  one per (booking, date, slot)
     */
    public static function of(array $rows): self
    {
        $occupancy = new self;
        foreach ($rows as $row) {
            $key = self::key($row['date'], $row['slot']);
            $occupancy->heads[$key] = ($occupancy->heads[$key] ?? 0) + $row['heads'];
            foreach ($row['detailer_ids'] as $id) {
                $occupancy->busy[$key][$id] = true;
            }
        }

        return $occupancy;
    }

    public function headsAt(string $date, int $slot): int
    {
        return $this->heads[self::key($date, $slot)] ?? 0;
    }

    /**
     * @return list<string>
     */
    public function busyAt(string $date, int $slot): array
    {
        return array_keys($this->busy[self::key($date, $slot)] ?? []);
    }

    /**
     * The fewest detailers free at any cell: the binding constraint. Null for
     * no cells at all.
     *
     * @param  list<array{date: string, slot: int}>  $cells
     */
    public function minRemaining(array $cells, int $pool): ?int
    {
        $min = null;
        foreach ($cells as $cell) {
            $free = $pool - $this->headsAt($cell['date'], $cell['slot']);
            $min = $min === null ? $free : min($min, $free);
        }

        return $min;
    }

    /**
     * Which of `$detailerIds` are already named on another booking in any cell.
     *
     * @param  list<array{date: string, slot: int}>  $cells
     * @param  list<string>  $detailerIds
     * @return list<string>
     */
    public function conflicts(array $cells, array $detailerIds): array
    {
        $taken = [];
        foreach ($cells as $cell) {
            foreach ($this->busyAt($cell['date'], $cell['slot']) as $id) {
                if (in_array($id, $detailerIds, true)) {
                    $taken[$id] = true;
                }
            }
        }

        return array_keys($taken);
    }

    private static function key(string $date, int $slot): string
    {
        return $date.'|'.$slot;
    }
}

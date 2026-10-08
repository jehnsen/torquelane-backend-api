<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Shared\JsMath;

/** Port of ../web/lib/pms.ts `summariseFleet`: the dashboard KPIs. */
final readonly class FleetSummary
{
    public function __construct(
        public int $total,
        public int $compliant,
        public int $dueSoon,
        public int $overdue,
        public int $inService,
        public int $down,
        /** Share of vehicles with no overdue item, 0–100. */
        public int $complianceRate,
        public int $avgHealthScore,
        public float|int $totalOdometer,
    ) {}

    /**
     * @param  list<VehicleHealth>  $health
     */
    public static function of(array $health): self
    {
        $total = count($health);
        $count = fn (callable $predicate): int => count(array_filter($health, $predicate));
        $overdue = $count(fn (VehicleHealth $h): bool => $h->status === 'overdue');

        $scores = 0;
        $odometer = 0;
        foreach ($health as $h) {
            $scores += $h->healthScore;
            $odometer += $h->vehicle->odometer;
        }

        return new self(
            $total,
            $count(fn (VehicleHealth $h): bool => $h->status === 'ok'),
            $count(fn (VehicleHealth $h): bool => $h->status === 'due_soon'),
            $overdue,
            $count(fn (VehicleHealth $h): bool => $h->vehicle->status === 'in_service'),
            $count(fn (VehicleHealth $h): bool => $h->vehicle->status === 'down'),
            $total > 0 ? (int) JsMath::round((($total - $overdue) / $total) * 100) : 100,
            $total > 0 ? (int) JsMath::round($scores / $total) : 100,
            $odometer,
        );
    }
}

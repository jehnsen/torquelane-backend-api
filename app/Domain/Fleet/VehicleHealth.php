<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

/** Port of ../web's `VehicleHealth`. */
final readonly class VehicleHealth
{
    /**
     * @param  list<PmsItem>  $items  most urgent first
     */
    public function __construct(
        public VehicleFacts $vehicle,
        public array $items,
        /** ok | due_soon | overdue */
        public string $status,
        public int $overdueCount,
        public int $dueSoonCount,
        public ?PmsItem $nextItem,
        public int $healthScore,
    ) {}
}

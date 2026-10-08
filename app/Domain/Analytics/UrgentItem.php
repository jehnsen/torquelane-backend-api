<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Fleet\PmsItem;
use App\Domain\Fleet\VehicleFacts;

/** One non-compliant PMS item with its vehicle. */
final readonly class UrgentItem
{
    public function __construct(
        public VehicleFacts $vehicle,
        public PmsItem $item,
    ) {}
}

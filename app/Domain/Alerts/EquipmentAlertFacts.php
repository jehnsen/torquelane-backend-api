<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

/**
 * What the equipment alert rules read about one tool: where it sits in its
 * service plan and in its rated life, as the equipment monitor derived them.
 */
final readonly class EquipmentAlertFacts
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $maintenanceTask,
        /** `ok` | `due_soon` | `overdue`; null when the tool has no interval. */
        public ?string $serviceLevel,
        /** How far through its interval the furthest limit is (1 = reached). */
        public float $serviceProgress,
        /** The limit that trips first: `hours` | `cycles` | `cups` | `days`. */
        public ?string $binding,
        /** Days to the next service at the recent pace; null when there is no pace to go by. */
        public ?float $forecastDays,
        /** `healthy` | `aging` | `replace_soon` | `end_of_life`; null when no life is rated. */
        public ?string $lifeLevel,
        public float $lifeUsedHours,
        public float $lifeRatedHours,
    ) {}
}

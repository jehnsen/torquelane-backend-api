<?php

declare(strict_types=1);

namespace App\Domain\Equipment;

/** One entry of a tool's service history, as the reliability figures read it. */
final readonly class ServiceEvent
{
    public const string REPAIR = 'repair';

    public function __construct(
        public string $kind,
        public int $costCents,
        public float $downtimeHours,
        public int $performedAtMs,
    ) {}
}

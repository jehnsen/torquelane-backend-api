<?php

declare(strict_types=1);

namespace App\Domain\Maintenance;

/** One effective (not voided) reading. */
final readonly class MeterReading
{
    public function __construct(
        /** ULID: insertion order breaks same-day ties. */
        public string $id,
        public float|int $value,
        /** Y-m-d */
        public string $readOn,
    ) {}
}

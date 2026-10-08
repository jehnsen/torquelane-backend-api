<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

/** A catalogue task as the engine needs it. */
final readonly class ServiceTaskFacts
{
    public function __construct(
        public string $id,
        public string $name,
        public float|int $intervalKm,
        public int $intervalMonths,
        public bool $critical,
    ) {}
}

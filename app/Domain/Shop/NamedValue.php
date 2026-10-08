<?php

declare(strict_types=1);

namespace App\Domain\Shop;

/** A reporting row. `value` is centavos for revenue cuts, hours for turnaround. */
final readonly class NamedValue
{
    public function __construct(
        public string $name,
        public float|int $value,
        public ?string $meta = null,
    ) {}
}

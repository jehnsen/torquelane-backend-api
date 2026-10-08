<?php

declare(strict_types=1);

namespace App\Domain\Shop;

final readonly class PartsMargin
{
    public function __construct(
        public int $supplierProvidedCents,
        public int $ownStockCents,
        public int $marginCents,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Domain\Parts;

/**
 * Which part a service task consumes, and how many per service (../web
 * `SERVICE_ITEM_PARTS`). Order matters: the forecast meets usages in this
 * order, and ties in its ranking keep it.
 */
final readonly class PartUsage
{
    public function __construct(
        public string $serviceTaskId,
        public string $partId,
        public int $quantityPerService,
    ) {}
}

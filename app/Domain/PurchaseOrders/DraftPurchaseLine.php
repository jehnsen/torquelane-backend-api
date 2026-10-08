<?php

declare(strict_types=1);

namespace App\Domain\PurchaseOrders;

/**
 * One line of a purchase request drafted from the forecast. `serviceTaskIds`
 * and `vehicleIds` record the due items it covers, so the next forecast run
 * excludes them instead of counting them twice.
 */
final readonly class DraftPurchaseLine
{
    /**
     * @param  list<string>  $serviceTaskIds
     * @param  list<string>  $vehicleIds
     */
    public function __construct(
        public string $partId,
        public string $description,
        public int $quantity,
        public int $unitCostCents,
        public array $serviceTaskIds,
        public array $vehicleIds,
    ) {}

    public function totalCents(): int
    {
        return $this->quantity * $this->unitCostCents;
    }
}

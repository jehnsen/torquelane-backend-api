<?php

declare(strict_types=1);

namespace App\Domain\Parts;

/** One part's projected demand over the horizon (../web `PartDemandRow`). */
final readonly class PartDemandRow
{
    /**
     * @param  list<DemandContributor>  $contributingItems
     */
    public function __construct(
        public FleetPartFacts $part,
        public int $quantityRequired,
        public int $shortfall,
        /** shortfall × unit cost: what closing the gap costs. */
        public int $estimatedCostCents,
        /** Y-m-d: the earliest due date among the contributors. */
        public string $earliestNeededOn,
        /** A shortfall that lead time can no longer close before the earliest need. */
        public bool $leadTimeRisk,
        public array $contributingItems,
    ) {}
}

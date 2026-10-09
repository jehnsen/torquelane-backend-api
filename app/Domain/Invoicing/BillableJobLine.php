<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use App\Domain\Inventory\TaxClass;

/**
 * An APPROVED work-order line as the invoice reads it: its rates and its
 * STORED costs (what the customer authorised, R11). The database holds
 * cost = round(quantity × rate), so billing the rates reproduces them exactly.
 */
final readonly class BillableJobLine
{
    public function __construct(
        public string $id,
        public string $description,
        public string $quantity,
        public int $unitPartRateCents,
        public int $partCostCents,
        public string $labourHours,
        public int $labourRateCents,
        public int $labourCostCents,
        public ?string $serviceTaskId = null,
        public ?string $itemId = null,
        /** The shop-stock item's own tax class; parts without one are VATable. */
        public TaxClass $partsTaxClass = TaxClass::Vatable,
    ) {}
}

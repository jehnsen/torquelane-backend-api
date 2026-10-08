<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Approvals\LineApprovalStatus;
use App\Domain\WorkOrders\PartsSource;

/**
 * The money on one work-order line. Rates are the inputs (quantity and hours
 * as decimal strings, rates in centavos); `partCostCents` / `labourCostCents`
 * are STORED — the historical price the customer approved — and only
 * Billing::recalc may write them. Re-deriving them on read would let a later
 * rate change rewrite an authorised amount.
 */
final readonly class BillableLine
{
    public function __construct(
        public string $quantity,
        public int $unitPartRateCents,
        public string $labourHours,
        public int $labourRateCents,
        public int $partCostCents,
        public int $labourCostCents,
        public LineApprovalStatus $approvalStatus = LineApprovalStatus::Pending,
        public PartsSource $partsSource = PartsSource::SupplierProvided,
    ) {}

    /** The stored amount (../web approvals.ts lineCost): what approval bands and logs read. */
    public function cost(): int
    {
        return $this->partCostCents + $this->labourCostCents;
    }

    public function withStatus(LineApprovalStatus $status): self
    {
        return new self($this->quantity, $this->unitPartRateCents, $this->labourHours, $this->labourRateCents, $this->partCostCents, $this->labourCostCents, $status, $this->partsSource);
    }
}

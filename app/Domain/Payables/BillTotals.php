<?php

declare(strict_types=1);

namespace App\Domain\Payables;

/**
 * A vendor bill's totals, in centavos: purchases by VAT category (net of
 * VAT), the input VAT, the bill's total, what is withheld at source and what
 * is therefore payable to the vendor.
 * total = VATable + input VAT + VAT-exempt + zero-rated + non-VAT purchases;
 * payable = total − withholding.
 */
final readonly class BillTotals
{
    /**
     * @param  list<int>  $netByLine  net of VAT, per line, in the order the lines were given; sums to the net purchases
     */
    public function __construct(
        public int $vatablePurchasesCents,
        public int $vatExemptPurchasesCents,
        public int $zeroRatedPurchasesCents,
        public int $nonVatPurchasesCents,
        public int $inputVatCents,
        public int $totalCents,
        public int $withholdingBaseCents,
        public int $withholdingCents,
        public array $netByLine,
    ) {}

    /** What was bought, before VAT. */
    public function netPurchasesCents(): int
    {
        return $this->vatablePurchasesCents + $this->vatExemptPurchasesCents + $this->zeroRatedPurchasesCents + $this->nonVatPurchasesCents;
    }

    public function payableCents(): int
    {
        return $this->totalCents - $this->withholdingCents;
    }
}

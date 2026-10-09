<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

/**
 * An invoice's totals, in centavos, as the BIR invoice prints them:
 * VATable sales (net of VAT), VAT, VAT-exempt and zero-rated sales, and, for
 * a branch that is not VAT-registered, its sales (no VAT categories apply).
 * total due = VATable sales + VAT + VAT-exempt + zero-rated + non-VAT sales.
 */
final readonly class InvoiceTotals
{
    public function __construct(
        public int $vatableSalesCents,
        public int $vatExemptSalesCents,
        public int $zeroRatedSalesCents,
        public int $nonVatSalesCents,
        public int $discountTotalCents,
        public int $vatAmountCents,
        public int $totalDueCents,
    ) {}

    /** What was sold, before VAT. */
    public function netSalesCents(): int
    {
        return $this->vatableSalesCents + $this->vatExemptSalesCents + $this->zeroRatedSalesCents + $this->nonVatSalesCents;
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'vatable_sales_cents' => $this->vatableSalesCents,
            'vat_exempt_sales_cents' => $this->vatExemptSalesCents,
            'zero_rated_sales_cents' => $this->zeroRatedSalesCents,
            'non_vat_sales_cents' => $this->nonVatSalesCents,
            'discount_total_cents' => $this->discountTotalCents,
            'vat_amount_cents' => $this->vatAmountCents,
            'total_due_cents' => $this->totalDueCents,
        ];
    }
}

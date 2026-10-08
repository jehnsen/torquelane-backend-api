<?php

declare(strict_types=1);

namespace App\Domain\Billing;

/** An order's bill, in centavos, carrying the VAT rate it was taxed at. */
final readonly class BillingTotals
{
    public function __construct(
        public int $partsTotalCents,
        public int $labourTotalCents,
        public int $subTotalCents,
        public int $miscTotalCents,
        public int $taxTotalCents,
        /** Decimal string; "0" is a real rate (not VAT-registered), not a missing one. */
        public string $vatRatePct,
        public int $grandTotalCents,
    ) {}

    public static function zero(string $vatRatePct): self
    {
        return new self(0, 0, 0, 0, 0, $vatRatePct, 0);
    }
}

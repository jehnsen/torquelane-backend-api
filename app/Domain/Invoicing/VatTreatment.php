<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use Brick\Math\BigDecimal;

/**
 * How a branch's invoices treat VAT: whether the branch is VAT-registered,
 * whether its prices already include VAT (extract it) or not (add it), and
 * the rate. A branch that is not VAT-registered charges none, whatever the
 * rate says.
 */
final readonly class VatTreatment
{
    public function __construct(
        public bool $vatRegistered,
        public bool $pricesIncludeVat,
        /** Decimal string, e.g. "12". */
        public string $vatRatePct,
    ) {}

    /** The rate actually charged: none outside VAT registration. */
    public function effectiveRate(): BigDecimal
    {
        return $this->vatRegistered ? BigDecimal::of($this->vatRatePct) : BigDecimal::zero();
    }
}

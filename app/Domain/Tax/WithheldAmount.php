<?php

declare(strict_types=1);

namespace App\Domain\Tax;

/**
 * One amount of income withheld upon, as an alphalist or a 2307 reads it:
 * who, on what date, under which ATC, the income payment and the tax taken.
 * A voided document appears a second time with its amounts negated, on the
 * day it was voided, so a period's figures are what the books show for it.
 */
final readonly class WithheldAmount
{
    public function __construct(
        /** Y-m-d, Manila */
        public string $date,
        public string $partyId,
        public string $partyName,
        public ?string $partyTin,
        public ?string $partyAddress,
        public string $atcCode,
        /** Decimal string. */
        public string $ratePct,
        public int $taxBaseCents,
        public int $taxWithheldCents,
        public string $reference,
    ) {}
}

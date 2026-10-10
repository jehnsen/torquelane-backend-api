<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Postings;

use App\Domain\Inventory\TaxClass;
use App\Domain\Invoicing\InvoiceLineKind;

/** One invoice line as the ledger needs it: what kind of sale, its tax class, and its price before the discount. */
final readonly class InvoiceLineFacts
{
    public function __construct(
        public InvoiceLineKind $kind,
        public TaxClass $taxClass,
        /** round(quantity × unit price), before the discount (VAT-inclusive where the branch's prices are). */
        public int $grossCents,
        public int $discountCents,
    ) {}
}

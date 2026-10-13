<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Postings;

use App\Domain\Ledger\LedgerEvent;
use App\Domain\Receivables\PaymentMethod;

/** A prepaid package sold: the money is in, the service is owed. */
final readonly class PrepaidSaleFacts implements PostingFacts
{
    public function __construct(
        public string $packageId,
        public string $reference,
        public string $branchId,
        public string $customerAccountId,
        public string $customerName,
        public string $soldOn,
        public int $priceCents,
        public PaymentMethod $method,
        /** The VAT inside the price (a package is sold VAT-inclusive); none for a branch that is not VAT-registered. */
        public int $vatCents = 0,
    ) {}

    public function event(): LedgerEvent
    {
        return LedgerEvent::PrepaidSold;
    }
}

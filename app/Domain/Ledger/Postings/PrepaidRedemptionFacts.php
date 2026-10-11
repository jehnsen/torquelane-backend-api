<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Postings;

use App\Domain\Ledger\LedgerEvent;

/** A prepaid credit used: its share of the package's price is now earned. */
final readonly class PrepaidRedemptionFacts implements PostingFacts
{
    public function __construct(
        public string $redemptionId,
        public string $reference,
        public string $branchId,
        public string $customerAccountId,
        public string $redeemedOn,
        public int $amountCents,
        public string $memo,
    ) {}

    public function event(): LedgerEvent
    {
        return LedgerEvent::PrepaidRedeemed;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Postings;

use App\Domain\Ledger\LedgerEvent;

/**
 * Tax a customer withheld at source from what an invoice was owed, certified
 * on their BIR 2307: the receivable comes down and the shop holds a tax
 * credit it sets against its own income tax.
 */
final readonly class WithholdingFacts implements PostingFacts
{
    public function __construct(
        public string $certificateId,
        public string $invoiceNumber,
        public string $branchId,
        public string $customerAccountId,
        public string $customerName,
        public string $receivedOn,
        public int $amountCents,
        public string $atcCode,
    ) {}

    public function event(): LedgerEvent
    {
        return LedgerEvent::WithholdingReceived;
    }
}

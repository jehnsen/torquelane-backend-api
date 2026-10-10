<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Postings;

use App\Domain\Ledger\LedgerEvent;

/** Some of a payment applied to an invoice: the customer's deposit becomes a settled receivable. */
final readonly class CreditFacts implements PostingFacts
{
    public function __construct(
        public string $allocationId,
        public string $paymentNumber,
        public string $invoiceNumber,
        /** Where the money was received. */
        public string $paymentBranchId,
        /** Where the invoice was issued; differs from the payment's when the customer paid at another branch. */
        public string $invoiceBranchId,
        public string $customerAccountId,
        public string $allocatedOn,
        public int $amountCents,
    ) {}

    public function event(): LedgerEvent
    {
        return LedgerEvent::CreditApplied;
    }
}

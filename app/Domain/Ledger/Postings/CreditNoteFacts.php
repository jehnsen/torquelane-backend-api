<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Postings;

use App\Domain\Ledger\LedgerEvent;

/**
 * A credit note issued against an invoice: sales and output VAT go back, the
 * invoice's balance comes down by what it still owed (`appliedCents`), and
 * anything beyond that is the customer's credit (`creditCents`).
 * net + VAT = applied + credit.
 */
final readonly class CreditNoteFacts implements PostingFacts
{
    public function __construct(
        public string $creditNoteId,
        public string $number,
        public string $invoiceNumber,
        public string $branchId,
        public string $customerAccountId,
        public string $customerName,
        public string $issuedOn,
        /** What was credited before VAT. */
        public int $netCents,
        public int $vatCents,
        public int $appliedCents,
        public int $creditCents,
    ) {}

    public function event(): LedgerEvent
    {
        return LedgerEvent::CreditNoteIssued;
    }
}

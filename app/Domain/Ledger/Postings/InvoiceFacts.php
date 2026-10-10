<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Postings;

use App\Domain\Ledger\LedgerEvent;

/** An invoice that has just been issued: its stored totals and its lines. */
final readonly class InvoiceFacts implements PostingFacts
{
    /**
     * @param  list<InvoiceLineFacts>  $lines
     */
    public function __construct(
        public string $invoiceId,
        public string $number,
        public string $branchId,
        public string $customerAccountId,
        public string $buyerName,
        public string $issueDate,
        public bool $vatRegistered,
        public bool $pricesIncludeVat,
        /** Decimal string, e.g. "12". */
        public string $vatRatePct,
        public int $vatableSalesCents,
        public int $vatExemptSalesCents,
        public int $zeroRatedSalesCents,
        public int $nonVatSalesCents,
        public int $vatAmountCents,
        public int $totalDueCents,
        public array $lines,
    ) {}

    public function event(): LedgerEvent
    {
        return LedgerEvent::InvoiceIssued;
    }
}

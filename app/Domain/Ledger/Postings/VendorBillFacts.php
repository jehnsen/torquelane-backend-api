<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Postings;

use App\Domain\Ledger\LedgerEvent;

/**
 * A vendor bill recorded: what each line debits (net of VAT), the input VAT
 * on it, what was withheld at source, and what is therefore owed the vendor.
 * The lines' nets plus the input VAT equal the payable plus the withholding.
 */
final readonly class VendorBillFacts implements PostingFacts
{
    /**
     * @param  list<VendorBillLineFacts>  $lines
     */
    public function __construct(
        public string $billId,
        public string $number,
        public string $branchId,
        public string $vendorId,
        public string $vendorName,
        public string $billDate,
        public array $lines,
        public int $inputVatCents,
        public int $withholdingCents,
        public int $payableCents,
    ) {}

    public function event(): LedgerEvent
    {
        return LedgerEvent::VendorBillPosted;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Postings;

use App\Domain\Ledger\LedgerEvent;
use App\Domain\Receivables\PaymentMethod;

/** Money paid to a vendor against its bills: the payable goes down, and so does the cash it was paid from. */
final readonly class VendorPaymentFacts implements PostingFacts
{
    public function __construct(
        public string $paymentId,
        public string $number,
        public string $branchId,
        public string $vendorId,
        public string $vendorName,
        public string $paidOn,
        public int $amountCents,
        public PaymentMethod $method,
    ) {}

    public function event(): LedgerEvent
    {
        return LedgerEvent::VendorPaymentMade;
    }
}

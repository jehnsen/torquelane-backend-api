<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Postings;

use App\Domain\Ledger\LedgerEvent;
use App\Domain\Receivables\PaymentMethod;

/** Money given back to a customer out of the credit they hold. */
final readonly class RefundFacts implements PostingFacts
{
    public function __construct(
        public string $refundId,
        public string $number,
        public string $branchId,
        public string $customerAccountId,
        public string $customerName,
        public string $refundedOn,
        public int $amountCents,
        public PaymentMethod $method,
    ) {}

    public function event(): LedgerEvent
    {
        return LedgerEvent::RefundPaid;
    }
}

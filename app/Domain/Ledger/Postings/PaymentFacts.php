<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Postings;

use App\Domain\Ledger\LedgerEvent;
use App\Domain\Receivables\PaymentMethod;

/** Money received from a customer, whether or not it is yet applied to an invoice. */
final readonly class PaymentFacts implements PostingFacts
{
    public function __construct(
        public string $paymentId,
        public string $number,
        public string $branchId,
        public string $customerAccountId,
        public string $customerName,
        public string $receivedOn,
        public int $amountCents,
        public PaymentMethod $method,
    ) {}

    public function event(): LedgerEvent
    {
        return LedgerEvent::PaymentReceived;
    }
}

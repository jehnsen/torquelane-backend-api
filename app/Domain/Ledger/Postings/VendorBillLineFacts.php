<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Postings;

/**
 * One vendor bill line as the ledger reads it. A line that bills goods
 * received into stock clears what the receipt credited GR/IR with
 * (`receivedValueCents`) and books the difference from what the vendor
 * charged (`netCents`) as a purchase price variance. Any other line debits
 * its own account for its net.
 */
final readonly class VendorBillLineFacts
{
    public function __construct(
        public string $description,
        public int $netCents,
        public ?string $accountId = null,
        public ?int $receivedValueCents = null,
    ) {}
}

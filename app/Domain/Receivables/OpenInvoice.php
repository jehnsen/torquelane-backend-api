<?php

declare(strict_types=1);

namespace App\Domain\Receivables;

/** An invoice that still has a balance, as allocation and aging read it. Money in centavos. */
final readonly class OpenInvoice
{
    public function __construct(
        public string $id,
        public string $number,
        public string $customerAccountId,
        /** Y-m-d */
        public string $issueDate,
        /** Y-m-d */
        public string $dueDate,
        public int $totalDueCents,
        public int $paidCents,
    ) {}

    public function balanceCents(): int
    {
        return $this->totalDueCents - $this->paidCents;
    }
}

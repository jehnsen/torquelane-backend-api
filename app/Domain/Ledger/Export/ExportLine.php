<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Export;

/** One journal line, flattened for a file an accountant opens. */
final readonly class ExportLine
{
    public function __construct(
        public string $entryNumber,
        public string $entryDate,
        public string $event,
        public string $reference,
        public string $memo,
        public string $branchName,
        public string $accountCode,
        public string $accountName,
        public int $debitCents,
        public int $creditCents,
        public string $customerName = '',
        public string $lineMemo = '',
        public string $reversalOf = '',
    ) {}
}

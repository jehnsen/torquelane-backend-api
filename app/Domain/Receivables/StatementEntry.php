<?php

declare(strict_types=1);

namespace App\Domain\Receivables;

/**
 * One movement on an account's statement: an invoice issued (a charge), a
 * payment received (a credit), or the void of either (its reversal, on the
 * day it was voided). Money in centavos.
 */
final readonly class StatementEntry
{
    public const string INVOICE = 'invoice';

    public const string INVOICE_VOID = 'invoice_void';

    public const string PAYMENT = 'payment';

    public const string PAYMENT_VOID = 'payment_void';

    public function __construct(
        /** Y-m-d, Manila */
        public string $date,
        public string $kind,
        public string $documentId,
        public string $reference,
        public string $description,
        public int $chargeCents,
        public int $creditCents,
        /** Orders entries within a day (the instant it happened, then the id). */
        public string $sortKey,
    ) {}

    public function netCents(): int
    {
        return $this->chargeCents - $this->creditCents;
    }
}

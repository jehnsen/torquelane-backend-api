<?php

declare(strict_types=1);

namespace App\Domain\Receivables;

/**
 * One movement on an account's statement: an invoice issued (a charge), a
 * payment received, a credit note or tax withheld at source (credits), a
 * refund (a charge: money given back), or the void of any of them (its
 * reversal, on the day it was voided). Money in centavos.
 */
final readonly class StatementEntry
{
    public const string INVOICE = 'invoice';

    public const string INVOICE_VOID = 'invoice_void';

    public const string PAYMENT = 'payment';

    public const string PAYMENT_VOID = 'payment_void';

    public const string CREDIT_NOTE = 'credit_note';

    public const string CREDIT_NOTE_VOID = 'credit_note_void';

    public const string REFUND = 'refund';

    public const string REFUND_VOID = 'refund_void';

    public const string WITHHOLDING = 'withholding';

    public const string WITHHOLDING_VOID = 'withholding_void';

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

<?php

declare(strict_types=1);

namespace App\Domain\Tax;

/**
 * One document as the VAT return and the summary lists of sales and
 * purchases read it: who it was with, when it counts, and its amounts by VAT
 * category (net of VAT) with the VAT on them. A void or a credit note is a
 * row of its own with the amounts negated, dated the day it happened, so a
 * period adds up to what the books show for that period.
 */
final readonly class TaxableDocument
{
    public function __construct(
        /** Y-m-d, Manila */
        public string $date,
        /** `invoice`, `invoice_void`, `credit_note`, `credit_note_void`, `prepaid_sale`, `vendor_bill`, `vendor_bill_void`. */
        public string $kind,
        public string $reference,
        public string $partyId,
        public string $partyName,
        public ?string $partyTin,
        public ?string $partyAddress,
        public int $vatableCents,
        public int $vatExemptCents,
        public int $zeroRatedCents,
        public int $nonVatCents,
        public int $vatCents,
    ) {}

    /** Net of VAT. */
    public function netCents(): int
    {
        return $this->vatableCents + $this->vatExemptCents + $this->zeroRatedCents + $this->nonVatCents;
    }

    public function grossCents(): int
    {
        return $this->netCents() + $this->vatCents;
    }

    /** The same document taken back: every amount negated. */
    public function reversed(string $date, string $kind): self
    {
        return new self($date, $kind, $this->reference, $this->partyId, $this->partyName, $this->partyTin, $this->partyAddress, -$this->vatableCents, -$this->vatExemptCents, -$this->zeroRatedCents, -$this->nonVatCents, -$this->vatCents);
    }
}

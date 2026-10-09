<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use App\Domain\Billing\Billing;
use App\Domain\Inventory\TaxClass;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

/**
 * One invoice line before it is stored: quantity (decimal string) × unit
 * price (centavos, as the branch quotes it, so VAT-inclusive where its prices
 * include VAT), less a discount in centavos. Its total is rounded ONCE, half
 * up, to a centavo (R6).
 */
final readonly class InvoiceLineDraft
{
    public function __construct(
        public InvoiceLineKind $kind,
        public string $description,
        public string $quantity,
        public int $unitPriceCents,
        public int $discountCents = 0,
        public TaxClass $taxClass = TaxClass::Vatable,
        public ?string $workOrderId = null,
        public ?string $workOrderLineId = null,
        public ?string $itemId = null,
        public ?string $serviceTaskId = null,
    ) {
        if (! BigDecimal::of($quantity)->isPositive()) {
            throw new InvalidArgumentException('An invoice line bills a positive quantity.');
        }
        if ($unitPriceCents < 0 || $discountCents < 0) {
            throw new InvalidArgumentException('Prices and discounts are never negative.');
        }
        if ($discountCents > $this->grossCents()) {
            throw new InvalidArgumentException('A discount cannot exceed the line it is on.');
        }
    }

    /** round(quantity × unit price), before the discount. */
    public function grossCents(): int
    {
        return Billing::roundCents(BigDecimal::of($this->quantity)->multipliedBy($this->unitPriceCents));
    }

    public function totalCents(): int
    {
        return $this->grossCents() - $this->discountCents;
    }

    public function withDiscount(int $discountCents): self
    {
        return new self($this->kind, $this->description, $this->quantity, $this->unitPriceCents, $discountCents, $this->taxClass, $this->workOrderId, $this->workOrderLineId, $this->itemId, $this->serviceTaskId);
    }
}

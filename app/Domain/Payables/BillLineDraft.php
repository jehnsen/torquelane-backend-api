<?php

declare(strict_types=1);

namespace App\Domain\Payables;

use App\Domain\Billing\Billing;
use App\Domain\Inventory\TaxClass;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

/**
 * One vendor bill line before it is stored: quantity (decimal string) × unit
 * cost (centavos, as the vendor's invoice prints it, so VAT-inclusive where
 * the bill's prices include VAT). Its total is rounded ONCE, half up (R6).
 *
 * A goods line names the receipt line it bills. If those goods went on the
 * shelf, `receivedValueCents` is what the receipt credited GR/IR with; the
 * bill clears exactly that and books the rest as a price variance. Goods that
 * never went on the shelf (bought for one job), and every expense line, debit
 * `accountId` instead.
 */
final readonly class BillLineDraft
{
    public function __construct(
        public BillLineKind $kind,
        public string $description,
        public string $quantity,
        public int $unitCostCents,
        public TaxClass $taxClass = TaxClass::Vatable,
        public ?string $accountId = null,
        public ?string $goodsReceiptLineId = null,
        public ?int $receivedValueCents = null,
    ) {
        if (! BigDecimal::of($quantity)->isPositive()) {
            throw new InvalidArgumentException('A bill line bills a positive quantity.');
        }
        if ($unitCostCents < 0) {
            throw new InvalidArgumentException('A cost is never negative.');
        }
        if (($kind === BillLineKind::Goods) !== ($goodsReceiptLineId !== null)) {
            throw new InvalidArgumentException('A goods line names its receipt line, and only a goods line does.');
        }
        if ($receivedValueCents === null && $accountId === null) {
            throw new InvalidArgumentException('A bill line debits GR/IR or an account.');
        }
    }

    /** round(quantity × unit cost), as billed. */
    public function totalCents(): int
    {
        return Billing::roundCents(BigDecimal::of($this->quantity)->multipliedBy($this->unitCostCents));
    }

    /** Whether the line clears goods received into stock (GR/IR), not an account of its own. */
    public function clearsReceipt(): bool
    {
        return $this->receivedValueCents !== null;
    }
}

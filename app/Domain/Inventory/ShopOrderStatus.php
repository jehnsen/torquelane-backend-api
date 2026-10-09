<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use Brick\Math\BigDecimal;

/**
 * A shop purchase order's status as the API shows it. What is STORED is only
 * draft / issued / cancelled (the documents' own decisions); `partially_received`
 * and `received` are never stored: they follow from what the goods receipts
 * have taken in against what was ordered.
 */
enum ShopOrderStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case Cancelled = 'cancelled';

    /**
     * @param  'draft'|'issued'|'cancelled'  $stored
     * @param  list<array{ordered: string, received: string}>  $lines  per line, in the purchase unit
     */
    public static function derive(string $stored, array $lines): self
    {
        if ($stored === 'cancelled') {
            return self::Cancelled;
        }
        if ($stored === 'draft') {
            return self::Draft;
        }

        $received = false;
        $complete = $lines !== [];
        foreach ($lines as $line) {
            $got = BigDecimal::of($line['received']);
            $received = $received || $got->isPositive();
            $complete = $complete && $got->isGreaterThanOrEqualTo($line['ordered']);
        }

        return match (true) {
            $complete => self::Received,
            $received => self::PartiallyReceived,
            default => self::Issued,
        };
    }

    /** Whether more goods can still be taken in against it. */
    public function canReceive(): bool
    {
        return $this === self::Issued || $this === self::PartiallyReceived;
    }

    /** Whether the whole order can still be cancelled (nothing taken in). */
    public function canCancel(): bool
    {
        return $this === self::Draft || $this === self::Issued;
    }
}

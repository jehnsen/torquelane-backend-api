<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

/**
 * Why stock moved. The sign of a move's quantity says which way; each type
 * says which signs it may carry.
 *
 *  - opening      the first count of an item in a location (inbound, costed)
 *  - receipt      goods received against a purchase order (inbound, costed)
 *  - issue        parts issued to a work order (outbound)
 *  - return       the reversal of an issue (inbound) or of a receipt (outbound)
 *  - adjustment   a stock count's variance, either way
 *  - transfer_out / transfer_in   the two halves of one transfer document
 *  - consumption  used up inside the shop (outbound)
 */
enum MoveType: string
{
    case Opening = 'opening';
    case Receipt = 'receipt';
    case Issue = 'issue';
    case Return = 'return';
    case Adjustment = 'adjustment';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case Consumption = 'consumption';

    /** Whether a positive (+1) or negative (−1) quantity is legal; 0 = either. */
    public function direction(): int
    {
        return match ($this) {
            self::Opening, self::Receipt, self::TransferIn => 1,
            self::Issue, self::TransferOut, self::Consumption => -1,
            self::Return, self::Adjustment => 0,
        };
    }

    /** Whether an inbound move of this type must say what the goods cost. */
    public function requiresCost(): bool
    {
        return match ($this) {
            self::Opening, self::Receipt, self::TransferIn => true,
            default => false,
        };
    }
}

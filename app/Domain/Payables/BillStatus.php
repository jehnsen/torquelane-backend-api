<?php

declare(strict_types=1);

namespace App\Domain\Payables;

/**
 * A vendor bill's status. `void` is a decision; otherwise the status follows
 * what the payments that still stand have allocated to it (`settled`).
 */
enum BillStatus: string
{
    case Posted = 'posted';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Void = 'void';

    /** A standing bill by what is paid against what is payable (the total less what was withheld). */
    public static function settled(int $paidCents, int $payableCents): self
    {
        return match (true) {
            $paidCents >= $payableCents => self::Paid,
            $paidCents > 0 => self::PartiallyPaid,
            default => self::Posted,
        };
    }

    /** Still owed: it takes payments and ages. */
    public function isOpen(): bool
    {
        return $this === self::Posted || $this === self::PartiallyPaid;
    }

    public function isStanding(): bool
    {
        return $this !== self::Void;
    }

    public function label(): string
    {
        return match ($this) {
            self::Posted => 'Unpaid',
            self::PartiallyPaid => 'Partially paid',
            self::Paid => 'Paid',
            self::Void => 'Void',
        };
    }
}

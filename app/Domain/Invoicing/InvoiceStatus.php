<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

/**
 * An invoice's status. `draft` and `void` are decisions; between them the
 * status follows from what has been paid against the invoice (`settled`):
 * the allocations of the payments that still stand.
 */
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Void = 'void';

    /** An issued invoice by what is paid against it. Nothing due is paid as soon as it is issued. */
    public static function settled(int $paidCents, int $totalDueCents): self
    {
        return match (true) {
            $paidCents >= $totalDueCents => self::Paid,
            $paidCents > 0 => self::PartiallyPaid,
            default => self::Issued,
        };
    }

    /** Issued and not yet fully paid: it takes payments and ages. */
    public function isOpen(): bool
    {
        return $this === self::Issued || $this === self::PartiallyPaid;
    }

    /** Numbered and standing: counts as billed. */
    public function isStanding(): bool
    {
        return $this !== self::Draft && $this !== self::Void;
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Issued => 'Issued',
            self::PartiallyPaid => 'Partially paid',
            self::Paid => 'Paid',
            self::Void => 'Void',
        };
    }
}

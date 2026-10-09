<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

/** A stock count sheet: open while counting, then posted or cancelled. */
enum CountStatus: string
{
    case Open = 'open';
    case Posted = 'posted';
    case Cancelled = 'cancelled';
}

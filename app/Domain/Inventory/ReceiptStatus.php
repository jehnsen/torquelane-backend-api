<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

/** A goods receipt is posted, and can be voided once. */
enum ReceiptStatus: string
{
    case Posted = 'posted';
    case Voided = 'voided';
}

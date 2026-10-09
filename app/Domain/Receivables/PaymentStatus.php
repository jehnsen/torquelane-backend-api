<?php

declare(strict_types=1);

namespace App\Domain\Receivables;

/** A payment stands (`posted`) or was voided; a void payment's allocations no longer count. */
enum PaymentStatus: string
{
    case Posted = 'posted';
    case Void = 'void';
}

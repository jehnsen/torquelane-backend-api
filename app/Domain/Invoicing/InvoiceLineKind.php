<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

/** What a line bills: a job line's parts or labour, a job's flat fee, or a typed-in line. */
enum InvoiceLineKind: string
{
    case Parts = 'parts';
    case Labour = 'labour';
    case Fee = 'fee';
    case Manual = 'manual';
}

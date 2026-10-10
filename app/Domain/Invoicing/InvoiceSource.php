<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

/** Where an invoice's lines came from: closed work orders of one account, a completed detailing booking, or typed in. */
enum InvoiceSource: string
{
    case WorkOrders = 'work_orders';
    case Manual = 'manual';
    case Detailing = 'detailing';
}

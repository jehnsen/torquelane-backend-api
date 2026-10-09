<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

/** Where an invoice's lines came from: closed work orders of one account, or typed in. */
enum InvoiceSource: string
{
    case WorkOrders = 'work_orders';
    case Manual = 'manual';
}

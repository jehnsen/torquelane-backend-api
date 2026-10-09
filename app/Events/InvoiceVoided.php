<?php

declare(strict_types=1);

namespace App\Events;

/** `invoice.voided`: An issued invoice was voided (it keeps its number; its work orders may be invoiced again). */
final class InvoiceVoided extends BillingEvent
{
    public const string NAME = 'invoice.voided';

    public function name(): string
    {
        return self::NAME;
    }
}

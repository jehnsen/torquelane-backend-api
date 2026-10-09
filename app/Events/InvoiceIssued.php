<?php

declare(strict_types=1);

namespace App\Events;

/** `invoice.issued`: An invoice was issued (numbered; it is now an immutable financial document). */
final class InvoiceIssued extends BillingEvent
{
    public const string NAME = 'invoice.issued';

    public function name(): string
    {
        return self::NAME;
    }
}

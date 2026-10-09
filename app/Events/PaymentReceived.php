<?php

declare(strict_types=1);

namespace App\Events;

/** `payment.received`: A payment was recorded (numbered; allocated to invoices or held as credit). */
final class PaymentReceived extends BillingEvent
{
    public const string NAME = 'payment.received';

    public function name(): string
    {
        return self::NAME;
    }
}

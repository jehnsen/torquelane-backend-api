<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A money event other systems may react to: dispatched only once the
 * transaction that made it has committed, so a rolled-back invoice or payment
 * never announces itself. Listeners are queued (`PublishBillingEvent`), which
 * is where an e-invoicing submission integration plugs in later.
 */
abstract class BillingEvent implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly string $organizationId,
        /** The invoice or payment the event is about. */
        public readonly string $documentId,
        /** Its printed number (INV-…, PAY-…). */
        public readonly string $number,
    ) {}

    /** The event's published name: `invoice.issued`, `invoice.voided`, `payment.received`. */
    abstract public function name(): string;
}

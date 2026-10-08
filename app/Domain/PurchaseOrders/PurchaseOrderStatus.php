<?php

declare(strict_types=1);

namespace App\Domain\PurchaseOrders;

/** ../web `PurchaseOrderStatus`. */
enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Received = 'received';
    case Cancelled = 'cancelled';

    /** Still covering demand: the forecast excludes what an open order was raised for. */
    public function isOpen(): bool
    {
        return $this === self::Draft || $this === self::Sent;
    }

    public function isTerminal(): bool
    {
        return ! $this->isOpen();
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\PurchaseOrders;

use App\Domain\Access\Capability;
use App\Domain\WorkOrders\TransitionCheck;

/**
 * The one place a purchase order's moves are legal or not:
 *
 *   draft → sent        issuing it to the vendor: the APPROVAL, held to the
 *                       issuer's band on the order's total (PurchaseOrders);
 *   sent  → received    receiving it restocks the account's parts;
 *   draft | sent → cancelled
 *
 * Received and cancelled are terminal. Every move needs `po:issue`.
 */
final class PurchaseOrderMachine
{
    private const array EDGES = [
        'draft' => ['sent', 'cancelled'],
        'sent' => ['received', 'cancelled'],
        'received' => [],
        'cancelled' => [],
    ];

    public static function canTransition(PurchaseOrderStatus $from, PurchaseOrderStatus $to): bool
    {
        return in_array($to->value, self::EDGES[$from->value], true);
    }

    /**
     * @return list<PurchaseOrderStatus>
     */
    public static function nextStatuses(PurchaseOrderStatus $from): array
    {
        return array_map(PurchaseOrderStatus::from(...), self::EDGES[$from->value]);
    }

    public static function checkTransition(PurchaseOrderStatus $from, PurchaseOrderStatus $to): TransitionCheck
    {
        if ($from === $to) {
            return TransitionCheck::deny("This purchase order is already {$to->value}.");
        }
        if (! self::canTransition($from, $to)) {
            return TransitionCheck::deny("A {$from->value} purchase order cannot become {$to->value}.");
        }

        return TransitionCheck::allow(Capability::PoIssue);
    }
}

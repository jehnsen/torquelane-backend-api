<?php

declare(strict_types=1);

namespace App\Domain\PurchaseOrders;

use App\Domain\Access\Role;
use App\Domain\Approvals\Approvals;
use App\Domain\Approvals\ApprovalSettings;
use App\Domain\Parts\DemandContributor;
use App\Domain\Parts\PartDemandRow;

/**
 * Purchase orders for a customer's own spare parts (../web store.ts
 * `generatePurchaseOrders` / `updatePurchaseOrderStatus`, po-export.ts).
 * Money in centavos; a line's total is quantity × unit cost, exactly.
 */
final class PurchaseOrders
{
    /**
     * Selected forecast rows → draft purchase orders, one per preferred
     * vendor in the order vendors are first met. A row with no shortfall is
     * skipped (stock already covers it); each line orders the shortfall at
     * the part's unit cost and records the due items it covers.
     *
     * @param  list<PartDemandRow>  $rows  the forecast, as computed now
     * @param  list<string>  $partIds  the rows chosen
     * @return list<DraftPurchaseOrder>
     */
    public static function fromDemand(array $rows, array $partIds): array
    {
        /** @var array<string, list<DraftPurchaseLine>> $byVendor */
        $byVendor = [];
        foreach ($rows as $row) {
            if ($row->shortfall <= 0 || ! in_array($row->part->id, $partIds, true)) {
                continue;
            }
            $byVendor[$row->part->preferredVendor][] = new DraftPurchaseLine(
                $row->part->id,
                $row->part->name,
                $row->shortfall,
                $row->part->unitCostCents,
                self::unique(array_map(fn (DemandContributor $c): string => $c->taskId, $row->contributingItems)),
                self::unique(array_map(fn (DemandContributor $c): string => $c->vehicleId, $row->contributingItems)),
            );
        }

        $drafts = [];
        foreach ($byVendor as $vendor => $lines) {
            $drafts[] = new DraftPurchaseOrder((string) $vendor, $lines);
        }

        return $drafts;
    }

    /**
     * @param  list<array{quantity: int, unit_cost_cents: int}>  $lines
     */
    public static function totalCents(array $lines): int
    {
        return array_sum(array_map(fn (array $line): int => $line['quantity'] * $line['unit_cost_cents'], $lines));
    }

    /**
     * Issuing a purchase order (draft → sent) is the approval of its spend,
     * held to the same bands as work: fleet managers, provider admins and
     * branch managers without limit; operations and purchasing officers up to
     * the operations ceiling; nobody else. The `po:issue` capability is
     * checked first (the policy); this is the authority beyond it.
     */
    public static function canIssue(Role $role, int $totalCents, ApprovalSettings $settings): bool
    {
        return Approvals::canApprove($role, $totalCents, $settings);
    }

    /**
     * First occurrences, in order (`[...new Set(list)]`).
     *
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function unique(array $values): array
    {
        return array_values(array_unique($values));
    }
}

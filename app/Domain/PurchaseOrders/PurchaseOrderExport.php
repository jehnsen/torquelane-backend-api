<?php

declare(strict_types=1);

namespace App\Domain\PurchaseOrders;

/**
 * Port of ../web/lib/po-export.ts: the same sheets, columns and widths, one
 * row per line. Money columns carry centavos; the writer renders them as
 * pesos to two decimals.
 */
final class PurchaseOrderExport
{
    /** One purchase order (`exportPurchaseOrderToExcel`), the sheet named after its reference. */
    public static function order(ExportOrder $order): ExportTable
    {
        return new ExportTable(
            mb_substr($order->reference, 0, 31),
            ['Reference', 'Vendor', 'Description', 'Quantity', 'Unit cost', 'Line total'],
            [4, 5],
            array_map(fn (array $line): array => [
                $order->reference,
                $order->vendor,
                $line['description'],
                $line['quantity'],
                $line['unit_cost_cents'],
                $line['quantity'] * $line['unit_cost_cents'],
            ], $order->lines),
            [14, 26, 30, 10, 12, 12],
        );
    }

    /**
     * Every order given (`exportPurchaseOrdersToExcel`): one row per line; an
     * order without lines still gets one row, carrying its total.
     *
     * @param  list<ExportOrder>  $orders
     */
    public static function orders(array $orders): ExportTable
    {
        $rows = [];
        foreach ($orders as $order) {
            $head = [$order->reference, $order->vendor, $order->status->value, $order->createdOn, $order->createdBy];
            if ($order->lines === []) {
                $rows[] = [...$head, '', null, null, 0];

                continue;
            }
            foreach ($order->lines as $line) {
                $rows[] = [...$head, $line['description'], $line['quantity'], $line['unit_cost_cents'], $line['quantity'] * $line['unit_cost_cents']];
            }
        }

        return new ExportTable(
            'Purchase orders',
            ['Reference', 'Vendor', 'Status', 'Created', 'Created by', 'Description', 'Quantity', 'Unit cost', 'Line total'],
            [7, 8],
            $rows,
            [14, 26, 12, 12, 18, 30, 10, 12, 12],
        );
    }

    /** Centavos → "1234.50" (exact; no float). */
    public static function pesos(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($cents, 100), $cents % 100);
    }
}

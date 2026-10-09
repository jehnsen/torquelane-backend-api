<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

/** What produced a stock move. */
enum StockSource: string
{
    case Manual = 'manual';
    case GoodsReceipt = 'goods_receipt';
    case WorkOrderLine = 'work_order_line';
    case StockCount = 'stock_count';
    case StockTransfer = 'stock_transfer';
}

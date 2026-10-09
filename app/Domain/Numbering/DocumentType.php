<?php

declare(strict_types=1);

namespace App\Domain\Numbering;

/**
 * Every numbered document (R8). The series exists from Phase 1; the documents
 * themselves arrive with their phases.
 */
enum DocumentType: string
{
    case WorkOrder = 'work_order';
    case Invoice = 'invoice';
    case Receipt = 'receipt';
    case PurchaseOrder = 'purchase_order';
    case GoodsReceipt = 'goods_receipt';
    case JournalEntry = 'journal_entry';
    /** The shop's own purchase orders; Phase 4's `purchase_order` series stays the customer accounts'. */
    case ShopPurchaseOrder = 'shop_purchase_order';
    case StockTransfer = 'stock_transfer';
    case StockCount = 'stock_count';

    public function defaultPrefix(): string
    {
        return match ($this) {
            self::WorkOrder => 'WO',
            self::Invoice => 'INV',
            self::Receipt => 'OR',
            self::PurchaseOrder => 'PO',
            self::GoodsReceipt => 'GR',
            self::JournalEntry => 'JE',
            self::ShopPurchaseOrder => 'SPO',
            self::StockTransfer => 'TR',
            self::StockCount => 'SC',
        };
    }
}

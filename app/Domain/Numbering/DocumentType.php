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
    /** Payments received (acknowledgment receipts, Phase 7). `receipt` (OR) is left for an official receipt, should one ever be needed. */
    case Payment = 'payment';
    /** Detailing bookings (Phase 9): numbered when made, so a customer can quote one. */
    case DetailingBooking = 'detailing_booking';
    /** Prepaid packages sold (Phase 11). */
    case PrepaidPackage = 'prepaid_package';
    /** Vendor bills recorded (Phase 12): our own number for a vendor's invoice. */
    case VendorBill = 'vendor_bill';
    /** Payments to vendors: the cash-out voucher. */
    case VendorPayment = 'vendor_payment';
    case CreditNote = 'credit_note';
    case Refund = 'refund';

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
            self::Payment => 'PAY',
            self::DetailingBooking => 'BK',
            self::PrepaidPackage => 'PP',
            self::VendorBill => 'VB',
            self::VendorPayment => 'PV',
            self::CreditNote => 'CN',
            self::Refund => 'RF',
        };
    }
}

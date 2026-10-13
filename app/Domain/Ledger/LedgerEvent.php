<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

/**
 * Every business event that posts a journal entry. Each posts once per
 * source (the database holds that): the invoice, the payment, the allocation
 * or the stock move that caused it.
 */
enum LedgerEvent: string
{
    case InvoiceIssued = 'invoice_issued';
    case InvoiceVoided = 'invoice_voided';
    case PaymentReceived = 'payment_received';
    case PaymentVoided = 'payment_voided';
    /** Money held as a customer deposit applied to an invoice. */
    case CreditApplied = 'credit_applied';
    /** The application undone, because the payment was voided. */
    case CreditReversed = 'credit_reversed';
    case StockOpening = 'stock_opening';
    case StockReceipt = 'stock_receipt';
    /** A receipt voided: the goods go back out. */
    case StockReceiptReturn = 'stock_receipt_return';
    case StockIssue = 'stock_issue';
    /** Parts coming back off a job. */
    case StockReturn = 'stock_return';
    case StockConsumption = 'stock_consumption';
    case StockAdjustment = 'stock_adjustment';
    case StockTransfer = 'stock_transfer';
    /** Parts taken from stock to service a tool: an expense, not a cost of sales. */
    case StockMaintenance = 'stock_maintenance';
    /** Those parts put back, because the service entry was removed. */
    case StockMaintenanceReturn = 'stock_maintenance_return';
    /** A prepaid package sold: cash in, service owed (Phase 11). */
    case PrepaidSold = 'prepaid_sold';
    /** A prepaid credit used: its share of the price is earned. */
    case PrepaidRedeemed = 'prepaid_redeemed';
    /** A vendor's bill recorded: an expense or received goods become a payable (Phase 12). */
    case VendorBillPosted = 'vendor_bill_posted';
    case VendorBillVoided = 'vendor_bill_voided';
    /** Money paid out to a vendor against its bills. */
    case VendorPaymentMade = 'vendor_payment_made';
    case VendorPaymentVoided = 'vendor_payment_voided';
    /** A credit note against an issued invoice: sales and VAT go back. */
    case CreditNoteIssued = 'credit_note_issued';
    case CreditNoteVoided = 'credit_note_voided';
    /** A customer's credit paid back to them. */
    case RefundPaid = 'refund_paid';
    case RefundVoided = 'refund_voided';
    /** Tax a customer withheld at source, certified on a BIR 2307. */
    case WithholdingReceived = 'withholding_received';
    case WithholdingVoided = 'withholding_voided';
    /** A typed-in journal voucher. */
    case ManualJournal = 'manual_journal';
    case ManualJournalReversed = 'manual_journal_reversed';
    /** The year-end closing entry: profit or loss moved to Retained Earnings. */
    case YearClosed = 'year_closed';

    public function label(): string
    {
        return match ($this) {
            self::InvoiceIssued => 'Invoice issued',
            self::InvoiceVoided => 'Invoice voided',
            self::PaymentReceived => 'Payment received',
            self::PaymentVoided => 'Payment voided',
            self::CreditApplied => 'Credit applied to an invoice',
            self::CreditReversed => 'Credit application reversed',
            self::StockOpening => 'Opening stock',
            self::StockReceipt => 'Goods received',
            self::StockReceiptReturn => 'Goods receipt voided',
            self::StockIssue => 'Parts issued to a job',
            self::StockReturn => 'Parts returned from a job',
            self::StockConsumption => 'Stock consumed',
            self::StockAdjustment => 'Stock adjustment',
            self::StockTransfer => 'Stock transfer',
            self::StockMaintenance => 'Parts used on equipment',
            self::StockMaintenanceReturn => 'Parts returned from equipment',
            self::PrepaidSold => 'Prepaid package sold',
            self::PrepaidRedeemed => 'Prepaid credit redeemed',
            self::VendorBillPosted => 'Vendor bill recorded',
            self::VendorBillVoided => 'Vendor bill voided',
            self::VendorPaymentMade => 'Vendor paid',
            self::VendorPaymentVoided => 'Vendor payment voided',
            self::CreditNoteIssued => 'Credit note issued',
            self::CreditNoteVoided => 'Credit note voided',
            self::RefundPaid => 'Refund paid',
            self::RefundVoided => 'Refund voided',
            self::WithholdingReceived => 'Withholding certificate received',
            self::WithholdingVoided => 'Withholding certificate voided',
            self::ManualJournal => 'Journal voucher',
            self::ManualJournalReversed => 'Journal voucher reversed',
            self::YearClosed => 'Year-end close',
        };
    }

    /** What the entry's `source_id` points at. */
    public function sourceType(): string
    {
        return match ($this) {
            self::InvoiceIssued, self::InvoiceVoided => 'invoice',
            self::PaymentReceived, self::PaymentVoided => 'payment',
            self::CreditApplied, self::CreditReversed => 'payment_allocation',
            self::PrepaidSold => 'prepaid_package',
            self::PrepaidRedeemed => 'prepaid_redemption',
            self::VendorBillPosted, self::VendorBillVoided => 'vendor_bill',
            self::VendorPaymentMade, self::VendorPaymentVoided => 'vendor_payment',
            self::CreditNoteIssued, self::CreditNoteVoided => 'credit_note',
            self::RefundPaid, self::RefundVoided => 'refund',
            self::WithholdingReceived, self::WithholdingVoided => 'withholding_certificate',
            self::ManualJournal, self::ManualJournalReversed => 'manual_journal',
            self::YearClosed => 'fiscal_year',
            default => 'stock_move',
        };
    }

    /** Whether the event undoes an earlier entry. */
    public function isReversal(): bool
    {
        return match ($this) {
            self::InvoiceVoided, self::PaymentVoided, self::CreditReversed,
            self::VendorBillVoided, self::VendorPaymentVoided, self::CreditNoteVoided, self::RefundVoided,
            self::WithholdingVoided, self::ManualJournalReversed => true,
            default => false,
        };
    }
}

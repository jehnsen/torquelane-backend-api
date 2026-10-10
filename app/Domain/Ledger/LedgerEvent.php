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
        };
    }

    /** What the entry's `source_id` points at. */
    public function sourceType(): string
    {
        return match ($this) {
            self::InvoiceIssued, self::InvoiceVoided => 'invoice',
            self::PaymentReceived, self::PaymentVoided => 'payment',
            self::CreditApplied, self::CreditReversed => 'payment_allocation',
            default => 'stock_move',
        };
    }

    /** Whether the event undoes an earlier entry. */
    public function isReversal(): bool
    {
        return match ($this) {
            self::InvoiceVoided, self::PaymentVoided, self::CreditReversed => true,
            default => false,
        };
    }
}

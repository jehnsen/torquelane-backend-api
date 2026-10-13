<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Postings;

use App\Domain\Ledger\AccountRef;
use App\Domain\Ledger\JournalDraft;
use App\Domain\Ledger\LedgerEvent;
use App\Domain\Ledger\PostingLine;
use App\Domain\Ledger\RuleKey;
use InvalidArgumentException;

/**
 * The Phase 12 rulebook: payables, the non-cash ways an invoice is settled,
 * money going back to a customer, typed-in vouchers and the year-end close.
 * Pure, like the rest of `Postings`.
 *
 * | Event | Dr | Cr |
 * |---|---|---|
 * | vendor bill | GR/IR Clearing (goods received into stock, at the receipt's value), the line's own account (an expense, or goods bought for a job), Purchase Price Variance (billed above the receipt), Input VAT | Accounts Payable (the total less what was withheld), Withholding Tax Payable, Purchase Price Variance (billed below the receipt) |
 * | vendor payment | Accounts Payable | the cash-side account of the method |
 * | credit note | Sales Returns & Allowances, Output VAT | Accounts Receivable (what the invoice still owed), Customer Deposits (the rest: the customer's credit) |
 * | refund | Customer Deposits | the cash-side account of the method |
 * | 2307 received | Creditable Withholding Tax | Accounts Receivable |
 * | year closed | every revenue account | every expense account; Retained Earnings takes the difference |
 */
final class SubledgerPostings
{
    public static function vendorBill(VendorBillFacts $facts): JournalDraft
    {
        $branch = $facts->branchId;
        $memo = "Bill {$facts->number} — {$facts->vendorName}";
        $lines = [];
        $debits = 0;
        foreach ($facts->lines as $line) {
            if ($line->receivedValueCents !== null) {
                // Clears exactly what the receipt left in GR/IR; the vendor's price difference is a variance, not stock.
                $lines[] = PostingLine::debit(RuleKey::GoodsReceivedClearing, $line->receivedValueCents, $branch, memo: $line->description, vendorId: $facts->vendorId);
                $variance = $line->netCents - $line->receivedValueCents;
                if ($variance > 0) {
                    $lines[] = PostingLine::debit(RuleKey::PurchasePriceVariance, $variance, $branch, memo: "Billed above receipt: {$line->description}");
                } elseif ($variance < 0) {
                    $lines[] = PostingLine::credit(RuleKey::PurchasePriceVariance, -$variance, $branch, memo: "Billed below receipt: {$line->description}");
                }
            } else {
                if ($line->accountId === null) {
                    throw new InvalidArgumentException('A bill line that clears no receipt names the account it debits.');
                }
                $lines[] = PostingLine::debit(AccountRef::id($line->accountId), $line->netCents, $branch, memo: $line->description);
            }
            $debits += $line->netCents;
        }
        if ($debits + $facts->inputVatCents !== $facts->payableCents + $facts->withholdingCents) {
            throw new InvalidArgumentException("Bill {$facts->number}: its lines and input VAT do not add up to what is payable and withheld.");
        }

        if ($facts->inputVatCents > 0) {
            $lines[] = PostingLine::debit(RuleKey::InputVat, $facts->inputVatCents, $branch, memo: $memo);
        }
        if ($facts->withholdingCents > 0) {
            $lines[] = PostingLine::credit(RuleKey::WithholdingPayable, $facts->withholdingCents, $branch, memo: "Withheld from {$facts->vendorName}", vendorId: $facts->vendorId);
        }
        $lines[] = PostingLine::credit(RuleKey::Payables, $facts->payableCents, $branch, memo: $memo, vendorId: $facts->vendorId);

        return new JournalDraft(LedgerEvent::VendorBillPosted, $facts->billDate, $branch, $facts->billId, $facts->number, $memo, self::withoutZeroes($lines));
    }

    public static function vendorPayment(VendorPaymentFacts $facts): JournalDraft
    {
        $memo = "Payment {$facts->number} to {$facts->vendorName} by {$facts->method->label()}";

        return new JournalDraft(
            LedgerEvent::VendorPaymentMade,
            $facts->paidOn,
            $facts->branchId,
            $facts->paymentId,
            $facts->number,
            $memo,
            [
                PostingLine::debit(RuleKey::Payables, $facts->amountCents, $facts->branchId, memo: $facts->number, vendorId: $facts->vendorId),
                PostingLine::credit(Postings::receivedInto($facts->method), $facts->amountCents, $facts->branchId, memo: $facts->number),
            ],
            paymentMethod: $facts->method->value,
        );
    }

    public static function creditNote(CreditNoteFacts $facts): JournalDraft
    {
        if ($facts->netCents + $facts->vatCents !== $facts->appliedCents + $facts->creditCents) {
            throw new InvalidArgumentException("Credit note {$facts->number}: what it credits is not what it applies.");
        }
        $branch = $facts->branchId;
        $memo = "Credit note {$facts->number} on {$facts->invoiceNumber} — {$facts->customerName}";

        return new JournalDraft(LedgerEvent::CreditNoteIssued, $facts->issuedOn, $branch, $facts->creditNoteId, $facts->number, $memo, self::withoutZeroes([
            PostingLine::debit(RuleKey::SalesReturns, $facts->netCents, $branch, $facts->customerAccountId, memo: $memo),
            PostingLine::debit(RuleKey::OutputVat, $facts->vatCents, $branch, memo: $memo),
            PostingLine::credit(RuleKey::Receivables, $facts->appliedCents, $branch, $facts->customerAccountId, memo: $facts->invoiceNumber),
            // Beyond what the invoice still owed, the credit is money the shop holds for the customer.
            PostingLine::credit(RuleKey::CustomerDeposits, $facts->creditCents, $branch, $facts->customerAccountId, memo: $facts->number),
        ]));
    }

    public static function refund(RefundFacts $facts): JournalDraft
    {
        return new JournalDraft(
            LedgerEvent::RefundPaid,
            $facts->refundedOn,
            $facts->branchId,
            $facts->refundId,
            $facts->number,
            "Refund {$facts->number} to {$facts->customerName} by {$facts->method->label()}",
            [
                PostingLine::debit(RuleKey::CustomerDeposits, $facts->amountCents, $facts->branchId, $facts->customerAccountId, memo: $facts->number),
                PostingLine::credit(Postings::receivedInto($facts->method), $facts->amountCents, $facts->branchId, memo: $facts->number),
            ],
            paymentMethod: $facts->method->value,
        );
    }

    public static function withholding(WithholdingFacts $facts): JournalDraft
    {
        $memo = "2307 from {$facts->customerName} on {$facts->invoiceNumber} ({$facts->atcCode})";

        return new JournalDraft(LedgerEvent::WithholdingReceived, $facts->receivedOn, $facts->branchId, $facts->certificateId, $facts->invoiceNumber, $memo, [
            PostingLine::debit(RuleKey::CreditableWithholding, $facts->amountCents, $facts->branchId, $facts->customerAccountId, memo: $memo),
            PostingLine::credit(RuleKey::Receivables, $facts->amountCents, $facts->branchId, $facts->customerAccountId, memo: $memo),
        ]);
    }

    public static function manualJournal(ManualJournalFacts $facts): JournalDraft
    {
        foreach ($facts->lines as $line) {
            if ($line->account->accountId === null) {
                throw new InvalidArgumentException('A journal voucher names its accounts, not posting rules.');
            }
        }

        return new JournalDraft(LedgerEvent::ManualJournal, $facts->entryDate, $facts->branchId, $facts->journalId, $facts->reference, $facts->memo, $facts->lines);
    }

    /**
     * The closing entry (dated 31 December while that month is open). Each revenue or expense balance
     * is posted back on the other side; what is left over in each branch is
     * that branch's profit (credited) or loss (debited) to Retained Earnings.
     */
    public static function yearClose(YearCloseFacts $facts): JournalDraft
    {
        $lines = [];
        /** @var array<string, int> $branchNet debits less credits of the year's profit-and-loss accounts */
        $branchNet = [];
        foreach ($facts->balances as $balance) {
            if ($balance->type->isBalanceSheet()) {
                throw new InvalidArgumentException("{$balance->code} is a balance-sheet account; it is not closed at year end.");
            }
            $branchId = $balance->branchId ?? $facts->branchId;
            $net = $balance->netDebitCents();
            if ($net === 0) {
                continue;
            }
            $memo = "Close {$balance->code} {$balance->name} for {$facts->year}";
            $lines[] = $net > 0
                ? PostingLine::credit(AccountRef::id($balance->accountId), $net, $branchId, memo: $memo)
                : PostingLine::debit(AccountRef::id($balance->accountId), -$net, $branchId, memo: $memo);
            $branchNet[$branchId] = ($branchNet[$branchId] ?? 0) + $net;
        }
        foreach ($branchNet as $branchId => $net) {
            if ($net === 0) {
                continue;
            }
            // Debits exceeded credits: expenses outran revenue, a loss, which reduces retained earnings.
            $lines[] = $net > 0
                ? PostingLine::debit(RuleKey::RetainedEarnings, $net, $branchId, memo: "Net loss for {$facts->year}")
                : PostingLine::credit(RuleKey::RetainedEarnings, -$net, $branchId, memo: "Net profit for {$facts->year}");
        }

        $memo = "Year-end close {$facts->year}";
        if ($facts->entryDate !== "{$facts->year}-12-31") {
            $memo .= " (December {$facts->year} was already closed; dated the first open day)";
        }

        return new JournalDraft(LedgerEvent::YearClosed, $facts->entryDate, $facts->branchId, $facts->fiscalYearId, "FY{$facts->year}", $memo, $lines);
    }

    /** The year's profit (positive) or loss, from the same balances the closing entry reads. */
    public static function netIncome(YearCloseFacts $facts): int
    {
        $net = 0;
        foreach ($facts->balances as $balance) {
            $net -= $balance->netDebitCents();
        }

        return $net;
    }

    /**
     * @param  list<PostingLine>  $lines
     * @return list<PostingLine>
     */
    private static function withoutZeroes(array $lines): array
    {
        return array_values(array_filter($lines, fn (PostingLine $line): bool => $line->debitCents !== 0 || $line->creditCents !== 0));
    }
}

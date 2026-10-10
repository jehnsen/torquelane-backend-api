<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Postings;

use App\Domain\Ledger\JournalDraft;
use App\Domain\Ledger\LedgerEvent;
use App\Domain\Ledger\PostingLine;
use App\Domain\Ledger\RuleKey;
use App\Domain\Receivables\PaymentMethod;
use InvalidArgumentException;

/**
 * `postingsFor(event)`: every business event, as the balanced entry it posts.
 * Pure: it names accounts by rule (or, in a reversal, by id) and never
 * touches storage, so the whole rulebook can be tested without a database.
 */
final class Postings
{
    public static function postingsFor(PostingFacts $facts): JournalDraft
    {
        return match (true) {
            $facts instanceof InvoiceFacts => InvoicePostings::issued($facts),
            $facts instanceof PaymentFacts => self::paymentReceived($facts),
            $facts instanceof CreditFacts => self::creditApplied($facts),
            $facts instanceof StockFacts => StockPostings::move($facts),
            $facts instanceof TransferFacts => StockPostings::transfer($facts),
            $facts instanceof ReversalFacts => self::reversal($facts),
            default => throw new InvalidArgumentException('No postings for '.$facts::class.'.'),
        };
    }

    /** The cash-side account a payment method lands in. */
    public static function receivedInto(PaymentMethod $method): RuleKey
    {
        return match ($method) {
            PaymentMethod::Cash => RuleKey::CashOnHand,
            PaymentMethod::BankTransfer, PaymentMethod::Check => RuleKey::CashBank,
            PaymentMethod::Gcash => RuleKey::ClearingGcash,
            PaymentMethod::Maya => RuleKey::ClearingMaya,
            PaymentMethod::Card => RuleKey::ClearingCard,
        };
    }

    private static function paymentReceived(PaymentFacts $facts): JournalDraft
    {
        $branch = $facts->branchId;

        return new JournalDraft(
            LedgerEvent::PaymentReceived,
            $facts->receivedOn,
            $branch,
            $facts->paymentId,
            $facts->number,
            "Payment {$facts->number} from {$facts->customerName} by {$facts->method->label()}",
            [
                PostingLine::debit(self::receivedInto($facts->method), $facts->amountCents, $branch, memo: $facts->number),
                // Held as the customer's deposit until it is applied to an invoice (a credit application follows).
                PostingLine::credit(RuleKey::CustomerDeposits, $facts->amountCents, $branch, $facts->customerAccountId, memo: $facts->number),
            ],
            paymentMethod: $facts->method->value,
        );
    }

    private static function creditApplied(CreditFacts $facts): JournalDraft
    {
        return new JournalDraft(
            LedgerEvent::CreditApplied,
            $facts->allocatedOn,
            $facts->paymentBranchId,
            $facts->allocationId,
            $facts->paymentNumber,
            "{$facts->paymentNumber} applied to {$facts->invoiceNumber}",
            [
                PostingLine::debit(RuleKey::CustomerDeposits, $facts->amountCents, $facts->paymentBranchId, $facts->customerAccountId, memo: $facts->invoiceNumber),
                PostingLine::credit(RuleKey::Receivables, $facts->amountCents, $facts->invoiceBranchId, $facts->customerAccountId, memo: $facts->paymentNumber),
            ],
            $facts->invoiceBranchId === $facts->paymentBranchId ? null : $facts->invoiceBranchId,
        );
    }

    private static function reversal(ReversalFacts $facts): JournalDraft
    {
        if (! $facts->event->isReversal()) {
            throw new InvalidArgumentException("{$facts->event->value} is not a reversal.");
        }

        return new JournalDraft(
            $facts->event,
            $facts->entryDate,
            $facts->branchId,
            $facts->sourceId,
            $facts->reference,
            trim("Reverses {$facts->reversedNumber}: {$facts->reason}", ': '),
            array_map(fn (PostingLine $line): PostingLine => $line->mirrored(), $facts->original),
            $facts->counterBranchId,
            $facts->paymentMethod,
            $facts->reversalOfId,
        );
    }
}

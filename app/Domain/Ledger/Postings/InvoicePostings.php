<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Postings;

use App\Domain\Billing\Billing;
use App\Domain\Inventory\TaxClass;
use App\Domain\Invoicing\InvoiceLineKind;
use App\Domain\Ledger\Apportion;
use App\Domain\Ledger\JournalDraft;
use App\Domain\Ledger\LedgerEvent;
use App\Domain\Ledger\PostingLine;
use App\Domain\Ledger\RuleKey;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;

/**
 * Invoice issued (accrual): Dr Accounts Receivable for the total due; Cr the
 * sales accounts for what was sold (before discounts) and Dr Sales Discounts
 * for the discounts; Cr Output VAT for the VAT. Net of discounts the sales
 * credits are exactly the invoice's VATable + exempt + zero-rated + non-VAT
 * sales, so the entry balances to the centavo with no plug.
 *
 * The invoice stores totals per tax bucket, not per line (VAT is rounded once
 * per total, R6). Each bucket's sales are therefore split across its lines'
 * accounts by largest remainder (`Apportion`), so what is credited to Parts
 * and Labour always adds up to the stored figure.
 *
 * A VAT-inclusive branch's line prices (and discounts) carry the VAT, so the
 * discount shown against sales is its VAT-free part, rounded once per bucket.
 */
final class InvoicePostings
{
    public static function issued(InvoiceFacts $facts): JournalDraft
    {
        $buckets = [
            ['net' => $facts->vatableSalesCents, 'lines' => self::inBucket($facts, TaxClass::Vatable)],
            ['net' => $facts->vatExemptSalesCents, 'lines' => self::inBucket($facts, TaxClass::VatExempt)],
            ['net' => $facts->zeroRatedSalesCents, 'lines' => self::inBucket($facts, TaxClass::ZeroRated)],
            ['net' => $facts->nonVatSalesCents, 'lines' => self::inBucket($facts, null)],
        ];

        /** @var array<string, int> $sales */
        $sales = [];
        $discounts = 0;
        foreach ($buckets as $bucket) {
            $lines = $bucket['lines'];
            $discount = self::discountExVat($facts, $lines, $bucket['net']);
            $gross = $bucket['net'] + $discount;
            if ($gross === 0) {
                continue;
            }

            $weights = [];
            foreach ($lines as $line) {
                $key = self::saleKey($line->kind)->value;
                $weights[$key] = ($weights[$key] ?? 0) + $line->grossCents;
            }
            foreach (Apportion::split($gross, $weights === [] ? [RuleKey::SalesManual->value => 0] : $weights) as $key => $cents) {
                $sales[$key] = ($sales[$key] ?? 0) + $cents;
            }
            $discounts += $discount;
        }

        $branch = $facts->branchId;
        $customer = $facts->customerAccountId;
        $lines = [PostingLine::debit(RuleKey::Receivables, $facts->totalDueCents, $branch, $customer, memo: $facts->number)];
        if ($discounts > 0) {
            $lines[] = PostingLine::debit(RuleKey::SalesDiscounts, $discounts, $branch, memo: $facts->number);
        }
        foreach ($sales as $key => $cents) {
            if ($cents > 0) {
                $lines[] = PostingLine::credit(RuleKey::from($key), $cents, $branch, memo: $facts->number);
            }
        }
        if ($facts->vatAmountCents > 0) {
            $lines[] = PostingLine::credit(RuleKey::OutputVat, $facts->vatAmountCents, $branch, memo: $facts->number);
        }
        if (count($lines) < 2) {
            // An invoice for nothing: keep the entry well formed.
            $lines[] = PostingLine::credit(RuleKey::SalesManual, 0, $branch, memo: $facts->number);
        }

        return new JournalDraft(
            LedgerEvent::InvoiceIssued,
            $facts->issueDate,
            $branch,
            $facts->invoiceId,
            $facts->number,
            "Invoice {$facts->number} to {$facts->buyerName}",
            $lines,
        );
    }

    /**
     * @return list<InvoiceLineFacts>
     */
    private static function inBucket(InvoiceFacts $facts, ?TaxClass $class): array
    {
        return array_values(array_filter($facts->lines, function (InvoiceLineFacts $line) use ($facts, $class): bool {
            // A branch outside VAT files every sale as non-VAT, whatever the line's class says.
            $bucket = $facts->vatRegistered ? $line->taxClass : null;

            return $bucket === $class;
        }));
    }

    /**
     * @param  list<InvoiceLineFacts>  $lines
     */
    private static function discountExVat(InvoiceFacts $facts, array $lines, int $net): int
    {
        $given = array_sum(array_map(fn (InvoiceLineFacts $line): int => $line->discountCents, $lines));
        if ($given === 0) {
            return 0;
        }
        $vatable = $facts->vatRegistered && $lines !== [] && $lines[0]->taxClass === TaxClass::Vatable;
        if (! ($vatable && $facts->pricesIncludeVat)) {
            return $given;
        }

        // The discount was given on a VAT-inclusive price: take the VAT out of it.
        $rate = BigDecimal::of($facts->vatRatePct);

        return Billing::roundCents(BigRational::of($given)->multipliedBy(100)->dividedBy($rate->plus(100)));
    }

    private static function saleKey(InvoiceLineKind $kind): RuleKey
    {
        return match ($kind) {
            InvoiceLineKind::Parts => RuleKey::SalesParts,
            InvoiceLineKind::Labour => RuleKey::SalesLabour,
            InvoiceLineKind::Fee => RuleKey::SalesFees,
            InvoiceLineKind::Manual => RuleKey::SalesManual,
        };
    }
}

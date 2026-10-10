<?php

declare(strict_types=1);

use App\Domain\Inventory\ItemType;
use App\Domain\Inventory\MoveType;
use App\Domain\Inventory\StockSource;
use App\Domain\Inventory\TaxClass;
use App\Domain\Invoicing\InvoiceLineDraft;
use App\Domain\Invoicing\InvoiceLineKind;
use App\Domain\Invoicing\Invoicing;
use App\Domain\Invoicing\VatTreatment;
use App\Domain\Ledger\AccountRef;
use App\Domain\Ledger\Apportion;
use App\Domain\Ledger\ChartOfAccounts;
use App\Domain\Ledger\JournalDraft;
use App\Domain\Ledger\LedgerEvent;
use App\Domain\Ledger\PostingLine;
use App\Domain\Ledger\Postings\CreditFacts;
use App\Domain\Ledger\Postings\InvoiceFacts;
use App\Domain\Ledger\Postings\InvoiceLineFacts;
use App\Domain\Ledger\Postings\PaymentFacts;
use App\Domain\Ledger\Postings\Postings;
use App\Domain\Ledger\Postings\ReversalFacts;
use App\Domain\Ledger\Postings\StockFacts;
use App\Domain\Ledger\Postings\TransferFacts;
use App\Domain\Ledger\RuleKey;
use App\Domain\Ledger\UnbalancedEntry;
use App\Domain\Receivables\PaymentMethod;

/*
 * The rulebook, with no database: every event type posts a draft that
 * balances to the centavo, and a draft that does not is refused before
 * anything could be written.
 */

const BR = 'branch-1';
const BR2 = 'branch-2';
const CUST = 'cust-1';

/**
 * An invoice's facts built from real lines through the real totals, so the
 * stored figures are exactly what Invoicing computes.
 *
 * @param  list<InvoiceLineDraft>  $lines
 */
function invoiceFacts(array $lines, bool $registered = true, bool $inclusive = false, string $rate = '12'): InvoiceFacts
{
    $totals = Invoicing::totals($lines, new VatTreatment($registered, $inclusive, $rate));

    return new InvoiceFacts(
        'inv-1', 'INV-2026-0001', BR, CUST, 'Actimed', '2026-10-09', $registered, $inclusive, $rate,
        $totals->vatableSalesCents, $totals->vatExemptSalesCents, $totals->zeroRatedSalesCents, $totals->nonVatSalesCents,
        $totals->vatAmountCents, $totals->totalDueCents,
        array_map(fn (InvoiceLineDraft $l): InvoiceLineFacts => new InvoiceLineFacts($l->kind, $l->taxClass, $l->grossCents(), $l->discountCents), $lines),
    );
}

function line(InvoiceLineKind $kind, string $qty, int $unit, int $discount = 0, TaxClass $class = TaxClass::Vatable): InvoiceLineDraft
{
    return new InvoiceLineDraft($kind, 'x', $qty, $unit, $discount, $class);
}

/** [debits, credits] of a draft keyed by rule, to assert exact lines. */
function byRule(JournalDraft $draft): array
{
    $out = [];
    foreach ($draft->lines as $l) {
        $key = $l->account->rule?->value ?? 'id';
        $out[$key] = ($out[$key] ?? 0) + $l->debitCents - $l->creditCents;
    }

    return $out;
}

dataset('invoices', fn (): array => [
    'exclusive: parts and labour' => [fn () => invoiceFacts([line(InvoiceLineKind::Parts, '2', 45_000), line(InvoiceLineKind::Labour, '1.5', 65_000), line(InvoiceLineKind::Fee, '1', 5_000)])],
    'inclusive: VAT extracted' => [fn () => invoiceFacts([line(InvoiceLineKind::Parts, '3', 11_200), line(InvoiceLineKind::Labour, '1', 33_333)], inclusive: true)],
    'with a discount (exclusive)' => [fn () => invoiceFacts([line(InvoiceLineKind::Parts, '1', 100_000, 10_000), line(InvoiceLineKind::Labour, '1', 50_000)])],
    'with a discount (inclusive)' => [fn () => invoiceFacts([line(InvoiceLineKind::Parts, '1', 112_000, 11_200), line(InvoiceLineKind::Labour, '1', 56_000)], inclusive: true)],
    'mixed tax classes' => [fn () => invoiceFacts([line(InvoiceLineKind::Parts, '1', 100_000), line(InvoiceLineKind::Parts, '1', 20_000, 0, TaxClass::VatExempt), line(InvoiceLineKind::Manual, '1', 30_000, 0, TaxClass::ZeroRated)])],
    'not VAT-registered' => [fn () => invoiceFacts([line(InvoiceLineKind::Parts, '2', 10_000), line(InvoiceLineKind::Labour, '1', 7_500, 500)], registered: false)],
    'tiny rounding amounts' => [fn () => invoiceFacts([line(InvoiceLineKind::Parts, '1', 10), line(InvoiceLineKind::Labour, '1', 10), line(InvoiceLineKind::Fee, '1', 10)])],
    'one cent' => [fn () => invoiceFacts([line(InvoiceLineKind::Manual, '1', 1)], inclusive: true)],
]);

it('posts an issued invoice that balances: receivable = sales - discounts + VAT', function (Closure $make) {
    $facts = $make();
    $draft = Postings::postingsFor($facts);

    $debits = array_sum(array_map(fn (PostingLine $l): int => $l->debitCents, $draft->lines));
    $credits = array_sum(array_map(fn (PostingLine $l): int => $l->creditCents, $draft->lines));
    // The entry is the receivable plus any discount shown against sales on the debit side.
    expect($debits)->toBe($credits);

    $net = byRule($draft);
    expect($net[RuleKey::Receivables->value])->toBe($facts->totalDueCents)
        ->and($net[RuleKey::OutputVat->value] ?? 0)->toBe(-$facts->vatAmountCents);

    // Net of the discounts the sales accounts hold exactly the invoice's stored sales buckets.
    $sales = 0;
    foreach (array_keys($net) as $key) {
        if (str_starts_with($key, 'sales.')) {
            $sales -= $net[$key];
        }
    }
    expect($sales)->toBe($facts->vatableSalesCents + $facts->vatExemptSalesCents + $facts->zeroRatedSalesCents + $facts->nonVatSalesCents);
})->with('invoices');

it('credits each sales account for what it sold and shows the discount against sales', function () {
    $draft = Postings::postingsFor(invoiceFacts([line(InvoiceLineKind::Parts, '1', 100_000, 10_000), line(InvoiceLineKind::Labour, '1', 50_000)]));
    $net = byRule($draft);

    expect($net[RuleKey::SalesParts->value])->toBe(-100_000)
        ->and($net[RuleKey::SalesLabour->value])->toBe(-50_000)
        ->and($net[RuleKey::SalesDiscounts->value])->toBe(10_000)
        ->and($net[RuleKey::OutputVat->value])->toBe(-16_800)
        ->and($net[RuleKey::Receivables->value])->toBe(156_800);
});

it('posts a payment to the account its method lands in, as a customer deposit', function (PaymentMethod $method, RuleKey $into) {
    $draft = Postings::postingsFor(new PaymentFacts('p1', 'PAY-2026-0001', BR, CUST, 'Actimed', '2026-10-09', 123_456, $method));

    expect(byRule($draft))->toEqual([$into->value => 123_456, RuleKey::CustomerDeposits->value => -123_456])
        ->and($draft->paymentMethod)->toBe($method->value);
})->with([
    'cash' => [PaymentMethod::Cash, RuleKey::CashOnHand],
    'bank transfer' => [PaymentMethod::BankTransfer, RuleKey::CashBank],
    'cheque' => [PaymentMethod::Check, RuleKey::CashBank],
    'gcash' => [PaymentMethod::Gcash, RuleKey::ClearingGcash],
    'maya' => [PaymentMethod::Maya, RuleKey::ClearingMaya],
    'card' => [PaymentMethod::Card, RuleKey::ClearingCard],
]);

it('applies credit: the deposit becomes a settled receivable', function () {
    $draft = Postings::postingsFor(new CreditFacts('a1', 'PAY-2026-0001', 'INV-2026-0001', BR, BR, CUST, '2026-10-10', 50_000));

    expect(byRule($draft))->toEqual([RuleKey::CustomerDeposits->value => 50_000, RuleKey::Receivables->value => -50_000])
        ->and($draft->counterBranchId)->toBeNull();
});

it('posts a credit applied across branches to each branch\'s own books', function () {
    $draft = Postings::postingsFor(new CreditFacts('a1', 'PAY-1', 'INV-1', BR, BR2, CUST, '2026-10-10', 50_000));

    expect($draft->counterBranchId)->toBe(BR2)
        ->and($draft->lines[0]->branchId)->toBe(BR)
        ->and($draft->lines[1]->branchId)->toBe(BR2);
});

/* ------------------------------------------------------------------ stock */

function stockFacts(LedgerEvent $event, int $delta, int $value, ItemType $type = ItemType::Part): StockFacts
{
    return new StockFacts($event, 'move-1', BR, '2026-10-09', $type, $delta, $value, 'GR-2026-0001', 'memo');
}

dataset('stock moves', fn (): array => [
    'goods received' => [LedgerEvent::StockReceipt, 120_000, 120_000],
    'goods received, average rounded up' => [LedgerEvent::StockReceipt, 120_003, 120_000],
    'receipt voided' => [LedgerEvent::StockReceiptReturn, -120_000, 120_000],
    'receipt voided, costing difference' => [LedgerEvent::StockReceiptReturn, -119_998, 120_000],
    'parts issued' => [LedgerEvent::StockIssue, -45_000, 45_000],
    'parts issued, rounding' => [LedgerEvent::StockIssue, -44_999, 45_000],
    'parts returned' => [LedgerEvent::StockReturn, 45_000, 45_000],
    'consumed' => [LedgerEvent::StockConsumption, -2_500, 2_500],
    'count gain' => [LedgerEvent::StockAdjustment, 9_000, 9_000],
    'count loss' => [LedgerEvent::StockAdjustment, -9_000, 9_000],
    'count loss, rounding' => [LedgerEvent::StockAdjustment, -9_001, 9_000],
    'opening balance' => [LedgerEvent::StockOpening, 692_800, 692_800],
    'a move worth nothing' => [LedgerEvent::StockIssue, 0, 0],
]);

it('posts a stock move that balances, whatever the costing rounding', function (LedgerEvent $event, int $delta, int $value) {
    $draft = Postings::postingsFor(stockFacts($event, $delta, $value));

    expect(array_sum(array_map(fn (PostingLine $l): int => $l->debitCents, $draft->lines)))
        ->toBe(array_sum(array_map(fn (PostingLine $l): int => $l->creditCents, $draft->lines)));
    // Inventory moves by exactly the book-value change.
    expect(byRule($draft)[RuleKey::Inventory->value])->toBe($delta);
})->with('stock moves');

it('receives into Inventory against GR/IR, and issues parts to the cost of sales for their item type', function () {
    expect(byRule(Postings::postingsFor(stockFacts(LedgerEvent::StockReceipt, 10_000, 10_000))))->toEqual([RuleKey::Inventory->value => 10_000, RuleKey::GoodsReceivedClearing->value => -10_000]);

    expect(byRule(Postings::postingsFor(stockFacts(LedgerEvent::StockIssue, -6_000, 6_000, ItemType::Part))))->toEqual([RuleKey::CogsParts->value => 6_000, RuleKey::Inventory->value => -6_000]);
    expect(byRule(Postings::postingsFor(stockFacts(LedgerEvent::StockIssue, -6_000, 6_000, ItemType::Consumable))))->toEqual([RuleKey::CogsConsumables->value => 6_000, RuleKey::Inventory->value => -6_000]);
    expect(byRule(Postings::postingsFor(stockFacts(LedgerEvent::StockIssue, -6_000, 6_000, ItemType::Ingredient))))->toEqual([RuleKey::CogsCafe->value => 6_000, RuleKey::Inventory->value => -6_000]);
});

it('shows average-cost rounding as its own visible line, never hidden', function () {
    $draft = Postings::postingsFor(stockFacts(LedgerEvent::StockIssue, -44_999, 45_000));

    expect(byRule($draft)[RuleKey::InventoryAdjustments->value])->toBe(-1)
        ->and(array_values(array_filter($draft->lines, fn (PostingLine $l): bool => $l->memo === 'Average-cost rounding')))->toHaveCount(1);
});

it('moves a transfer between branches with no profit or loss', function () {
    $draft = Postings::postingsFor(new TransferFacts('out-1', 'in-1', BR, BR2, '2026-10-09', -30_000, 30_000, 'TR-2026-0001', 'to Annex'));

    expect(array_keys(byRule($draft)))->toBe([RuleKey::Inventory->value])
        ->and($draft->counterBranchId)->toBe(BR2);
    $balanceByBranch = [];
    foreach ($draft->lines as $l) {
        $balanceByBranch[$l->branchId] = ($balanceByBranch[$l->branchId] ?? 0) + $l->debitCents - $l->creditCents;
    }
    expect($balanceByBranch)->toEqual([BR2 => 30_000, BR => -30_000]);
});

it('balances a transfer even when the two branches\' book values differ by rounding', function () {
    $draft = Postings::postingsFor(new TransferFacts('out-1', 'in-1', BR, BR2, '2026-10-09', -30_001, 30_000, 'TR', 'm'));

    expect(array_sum(array_map(fn (PostingLine $l): int => $l->debitCents - $l->creditCents, $draft->lines)))->toBe(0);
});

/* -------------------------------------------------------------- reversals */

it('reverses an entry by mirroring every line, so the two net to nothing', function () {
    $original = Postings::postingsFor(invoiceFacts([line(InvoiceLineKind::Parts, '2', 45_000), line(InvoiceLineKind::Labour, '1', 20_000, 1_000)]));
    $lines = array_map(fn (PostingLine $l): PostingLine => new PostingLine(AccountRef::id('acct-'.($l->account->rule?->value ?? '')), $l->debitCents, $l->creditCents, $l->branchId, $l->customerAccountId, null, $l->memo), $original->lines);

    $reversal = Postings::postingsFor(new ReversalFacts(LedgerEvent::InvoiceVoided, 'inv-1', 'entry-1', 'JE-2026-0001', 'INV-2026-0001', BR, null, null, '2026-10-20', 'wrong customer', $lines));

    expect($reversal->reversalOfId)->toBe('entry-1')
        ->and($reversal->totalCents)->toBe($original->totalCents);
    foreach ($lines as $i => $l) {
        expect($reversal->lines[$i]->debitCents)->toBe($l->creditCents)->and($reversal->lines[$i]->creditCents)->toBe($l->debitCents);
    }
});

it('refuses to use a non-reversal event for a reversal', function () {
    Postings::postingsFor(new ReversalFacts(LedgerEvent::StockIssue, 's', 'e', 'JE', 'r', BR, null, null, '2026-10-20', 'x', [PostingLine::debit(AccountRef::id('a'), 1, BR), PostingLine::credit(AccountRef::id('b'), 1, BR)]));
})->throws(InvalidArgumentException::class);

/* ------------------------------------------------------------- the guard */

it('throws before any write when a draft does not balance', function () {
    new JournalDraft(LedgerEvent::StockIssue, '2026-10-09', BR, 's', 'r', 'm', [
        PostingLine::debit(RuleKey::CogsParts, 100, BR),
        PostingLine::credit(RuleKey::Inventory, 99, BR),
    ]);
})->throws(UnbalancedEntry::class, 'does not balance: debits 100, credits 99');

it('throws when a draft has fewer than two lines', function () {
    new JournalDraft(LedgerEvent::StockIssue, '2026-10-09', BR, 's', 'r', 'm', [PostingLine::debit(RuleKey::CogsParts, 0, BR)]);
})->throws(UnbalancedEntry::class, 'needs at least two lines');

it('refuses a line that is both a debit and a credit, or negative', function () {
    expect(fn () => new PostingLine(AccountRef::rule(RuleKey::Inventory), 5, 5, BR))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new PostingLine(AccountRef::rule(RuleKey::Inventory), -5, 0, BR))->toThrow(InvalidArgumentException::class);
});

it('refuses a malformed entry date', function () {
    new JournalDraft(LedgerEvent::StockIssue, '09/10/2026', BR, 's', 'r', 'm', [PostingLine::debit(RuleKey::CogsParts, 1, BR), PostingLine::credit(RuleKey::Inventory, 1, BR)]);
})->throws(InvalidArgumentException::class);

it('maps every stock move type to a ledger event', function () {
    foreach (MoveType::cases() as $type) {
        foreach ([true, false] as $inbound) {
            expect(StockFacts::eventFor($type, StockSource::Manual, $inbound))->toBeInstanceOf(LedgerEvent::class);
        }
    }
});

it('has a default account and a type for every rule key', function () {
    foreach (RuleKey::cases() as $key) {
        expect($key->defaultCode())->not->toBe('')->and($key->label())->not->toBe('')->and($key->group())->not->toBe('');
    }
    // The seeded chart gives every default code an account of the type the rule needs.
    $chart = [];
    foreach (ChartOfAccounts::defaults() as $a) {
        $chart[$a['code']] = $a['type'];
    }
    foreach (RuleKey::cases() as $key) {
        expect($chart[$key->defaultCode()] ?? null)->toBe($key->accountType(), $key->value);
    }
});

/* -------------------------------------------------------------- apportion */

it('splits a total by weights so the parts add up exactly', function (int $total, array $weights) {
    $parts = Apportion::split($total, $weights);

    expect(array_sum($parts))->toBe($total)->and(array_keys($parts))->toBe(array_keys($weights));
    foreach ($parts as $part) {
        expect($part)->toBeGreaterThanOrEqual(0);
    }
})->with([
    'even' => [100, ['a' => 1, 'b' => 1]],
    'thirds' => [100, ['a' => 1, 'b' => 1, 'c' => 1]],
    'uneven' => [99_999, ['parts' => 45_000, 'labour' => 32_500, 'fee' => 5_000]],
    'one centavo, three ways' => [1, ['a' => 5, 'b' => 5, 'c' => 5]],
    'zero total' => [0, ['a' => 3, 'b' => 7]],
    'a zero weight gets nothing' => [10, ['a' => 0, 'b' => 4]],
    'all weights zero' => [7, ['a' => 0, 'b' => 0]],
]);

it('gives the largest remainder the extra centavo, ties to the earlier key', function () {
    expect(Apportion::split(100, ['a' => 1, 'b' => 1, 'c' => 1]))->toBe(['a' => 34, 'b' => 33, 'c' => 33]);
});

it('splits any total by any weights exactly (property check)', function () {
    mt_srand(8);
    for ($i = 0; $i < 400; $i++) {
        $weights = [];
        foreach (range(1, mt_rand(1, 6)) as $k) {
            $weights["k{$k}"] = mt_rand(0, 3) === 0 ? 0 : mt_rand(1, 500_000);
        }
        $total = mt_rand(0, 5_000_000);
        expect(array_sum(Apportion::split($total, $weights)))->toBe($total);
    }
});

it('posts every randomly generated invoice balanced (property check)', function () {
    mt_srand(80);
    $kinds = InvoiceLineKind::cases();
    $classes = TaxClass::cases();
    for ($i = 0; $i < 300; $i++) {
        $lines = [];
        foreach (range(1, mt_rand(1, 6)) as $_) {
            $unit = mt_rand(1, 400_000);
            $qty = (string) (mt_rand(1, 4000) / 1000);
            $gross = (int) round((float) $qty * $unit);
            $lines[] = new InvoiceLineDraft($kinds[array_rand($kinds)], 'l', $qty, $unit, mt_rand(0, 2) === 0 ? mt_rand(0, $gross) : 0, $classes[array_rand($classes)]);
        }
        $facts = invoiceFacts($lines, registered: mt_rand(0, 4) > 0, inclusive: (bool) mt_rand(0, 1), rate: ['12', '12', '5', '0'][mt_rand(0, 3)]);
        $draft = Postings::postingsFor($facts);

        $dr = array_sum(array_map(fn (PostingLine $l): int => $l->debitCents, $draft->lines));
        $cr = array_sum(array_map(fn (PostingLine $l): int => $l->creditCents, $draft->lines));
        expect($dr)->toBe($cr, "invoice #{$i}");
        expect(byRule($draft)[RuleKey::Receivables->value])->toBe($facts->totalDueCents, "invoice #{$i}");
    }
});

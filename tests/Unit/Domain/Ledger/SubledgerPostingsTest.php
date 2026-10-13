<?php

declare(strict_types=1);

use App\Domain\Inventory\TaxClass;
use App\Domain\Invoicing\VatTreatment;
use App\Domain\Ledger\AccountBalance;
use App\Domain\Ledger\AccountType;
use App\Domain\Ledger\BankStatement;
use App\Domain\Ledger\JournalDraft;
use App\Domain\Ledger\LedgerEvent;
use App\Domain\Ledger\PostingLine;
use App\Domain\Ledger\Postings\CreditNoteFacts;
use App\Domain\Ledger\Postings\Postings;
use App\Domain\Ledger\Postings\PrepaidSaleFacts;
use App\Domain\Ledger\Postings\RefundFacts;
use App\Domain\Ledger\Postings\SubledgerPostings;
use App\Domain\Ledger\Postings\VendorBillFacts;
use App\Domain\Ledger\Postings\VendorBillLineFacts;
use App\Domain\Ledger\Postings\VendorPaymentFacts;
use App\Domain\Ledger\Postings\WithholdingFacts;
use App\Domain\Ledger\Postings\YearCloseFacts;
use App\Domain\Ledger\RuleKey;
use App\Domain\Ledger\Side;
use App\Domain\Payables\BillLineDraft;
use App\Domain\Payables\BillLineKind;
use App\Domain\Payables\BillStatus;
use App\Domain\Payables\Payables;
use App\Domain\Receivables\PaymentMethod;

/*
 * The Phase 12 rulebook, without a database: payables arithmetic, and what
 * each new event posts. Every draft is balanced by construction.
 */

/** @return list<array{string, int, int}> rule key or account id, debit, credit */
function slSides(JournalDraft $draft): array
{
    return array_map(fn (PostingLine $line): array => [$line->account->rule->value ?? (string) $line->account->accountId, $line->debitCents, $line->creditCents], $draft->lines);
}

it('totals a vendor bill once per total, VAT on top or extracted, with tax withheld on the net', function () {
    $lines = [
        new BillLineDraft(BillLineKind::Expense, 'Rent', '1', 112_000, TaxClass::Vatable, 'acc-rent'),
        new BillLineDraft(BillLineKind::Expense, 'Association dues', '1', 5_000, TaxClass::VatExempt, 'acc-dues'),
    ];

    $onTop = Payables::totals($lines, new VatTreatment(true, false, '12'), '5');
    expect([$onTop->vatablePurchasesCents, $onTop->vatExemptPurchasesCents, $onTop->inputVatCents, $onTop->totalCents])->toBe([112_000, 5_000, 13_440, 130_440])
        ->and([$onTop->withholdingBaseCents, $onTop->withholdingCents, $onTop->payableCents()])->toBe([117_000, 5_850, 124_590])
        ->and($onTop->netByLine)->toBe([112_000, 5_000]);

    $extracted = Payables::totals($lines, new VatTreatment(true, true, '12'));
    expect([$extracted->vatablePurchasesCents, $extracted->inputVatCents, $extracted->totalCents])->toBe([100_000, 12_000, 117_000])
        ->and($extracted->withholdingCents)->toBe(0)
        ->and($extracted->netByLine)->toBe([100_000, 5_000]);

    // A vendor that is not VAT-registered: no input tax, whatever the lines say.
    $nonVat = Payables::totals($lines, new VatTreatment(false, true, '12'), '1');
    expect([$nonVat->nonVatPurchasesCents, $nonVat->vatablePurchasesCents, $nonVat->inputVatCents, $nonVat->totalCents, $nonVat->withholdingCents])->toBe([117_000, 0, 0, 117_000, 1_170]);
});

it('splits an extracted VAT across lines so they always add up to the stored net', function () {
    // Three lines of ₱33.33 VAT-inclusive: 99.99 gross, 10.71 VAT, 89.28 net, which no equal split gives.
    $lines = array_map(fn (int $i): BillLineDraft => new BillLineDraft(BillLineKind::Expense, "Line {$i}", '1', 3_333, TaxClass::Vatable, 'acc'), [1, 2, 3]);
    $totals = Payables::totals($lines, new VatTreatment(true, true, '12'));

    expect($totals->inputVatCents)->toBe(1_071)
        ->and($totals->vatablePurchasesCents)->toBe(8_928)
        ->and(array_sum($totals->netByLine))->toBe(8_928)
        ->and($totals->netByLine)->toBe([2_976, 2_976, 2_976])
        ->and(Payables::withholding(100_050, '1.5'))->toBe(1_501)
        ->and(Payables::dueDate('2026-01-31', 30))->toBe('2026-03-02');
});

it('refuses a bill line that names neither a receipt nor an account, and reads a bill\'s status from what is paid', function () {
    expect(fn () => new BillLineDraft(BillLineKind::Expense, 'Nothing', '1', 100))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new BillLineDraft(BillLineKind::Goods, 'No receipt', '1', 100, TaxClass::Vatable, 'acc'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new BillLineDraft(BillLineKind::Expense, 'Zero', '0', 100, TaxClass::Vatable, 'acc'))->toThrow(InvalidArgumentException::class)
        ->and(BillStatus::settled(0, 1_000))->toBe(BillStatus::Posted)
        ->and(BillStatus::settled(400, 1_000))->toBe(BillStatus::PartiallyPaid)
        ->and(BillStatus::settled(1_000, 1_000))->toBe(BillStatus::Paid)
        ->and(BillStatus::Void->isStanding())->toBeFalse();
});

it('posts a vendor bill: GR/IR at the receipt value, the difference to price variance, the rest payable', function () {
    $draft = Postings::postingsFor(new VendorBillFacts('bill-1', 'VB-2026-0001', 'br-1', 'vendor-1', 'Parts Co', '2026-10-08', [
        new VendorBillLineFacts('Brake pads', 100_500, null, 100_000),      // billed ₱5.00 above the receipt
        new VendorBillLineFacts('Oil filters', 49_000, null, 50_000),       // billed ₱10.00 below it
        new VendorBillLineFacts('Delivery', 5_000, 'acc-freight'),
    ], 18_540, 1_545, 171_495));

    expect($draft->event)->toBe(LedgerEvent::VendorBillPosted)
        ->and($draft->totalCents)->toBe(173_040 + 1_000)
        ->and(slSides($draft))->toBe([
            [RuleKey::GoodsReceivedClearing->value, 100_000, 0],
            [RuleKey::PurchasePriceVariance->value, 500, 0],
            [RuleKey::GoodsReceivedClearing->value, 50_000, 0],
            [RuleKey::PurchasePriceVariance->value, 0, 1_000],
            ['acc-freight', 5_000, 0],
            [RuleKey::InputVat->value, 18_540, 0],
            [RuleKey::WithholdingPayable->value, 0, 1_545],
            [RuleKey::Payables->value, 0, 171_495],
        ])
        // The payable carries the vendor: the payables subledger key.
        ->and($draft->lines[7]->vendorId)->toBe('vendor-1');

    // Figures that do not add up never become an entry.
    expect(fn () => SubledgerPostings::vendorBill(new VendorBillFacts('b', 'VB-X', 'br-1', 'v', 'V', '2026-10-08', [new VendorBillLineFacts('x', 100, 'acc')], 12, 0, 100)))->toThrow(InvalidArgumentException::class);
});

it('posts payments out, credit notes, refunds and withholding on the right sides', function () {
    $payment = Postings::postingsFor(new VendorPaymentFacts('pay-1', 'PV-2026-0001', 'br-1', 'vendor-1', 'Parts Co', '2026-10-08', 50_000, PaymentMethod::Check));
    expect(slSides($payment))->toBe([[RuleKey::Payables->value, 50_000, 0], [RuleKey::CashBank->value, 0, 50_000]])
        ->and($payment->paymentMethod)->toBe('check');

    // Part of the note comes off the invoice, the rest is the customer's credit.
    $note = Postings::postingsFor(new CreditNoteFacts('cn-1', 'CN-2026-0001', 'INV-2026-0003', 'br-1', 'acct-1', 'Actimed', '2026-10-08', 50_000, 6_000, 40_000, 16_000));
    expect(slSides($note))->toBe([
        [RuleKey::SalesReturns->value, 50_000, 0],
        [RuleKey::OutputVat->value, 6_000, 0],
        [RuleKey::Receivables->value, 0, 40_000],
        [RuleKey::CustomerDeposits->value, 0, 16_000],
    ]);
    // A note wholly applied posts no deposit line; one that applies something other than what it credits is refused.
    expect(slSides(SubledgerPostings::creditNote(new CreditNoteFacts('cn-2', 'CN-2', 'INV-1', 'br-1', 'acct-1', 'A', '2026-10-08', 1_000, 0, 1_000, 0))))->toBe([[RuleKey::SalesReturns->value, 1_000, 0], [RuleKey::Receivables->value, 0, 1_000]])
        ->and(fn () => SubledgerPostings::creditNote(new CreditNoteFacts('cn-3', 'CN-3', 'INV-1', 'br-1', 'acct-1', 'A', '2026-10-08', 1_000, 120, 1_000, 0)))->toThrow(InvalidArgumentException::class);

    expect(slSides(Postings::postingsFor(new RefundFacts('rf-1', 'RF-2026-0001', 'br-1', 'acct-1', 'Actimed', '2026-10-08', 16_000, PaymentMethod::Gcash))))->toBe([[RuleKey::CustomerDeposits->value, 16_000, 0], [RuleKey::ClearingGcash->value, 0, 16_000]])
        ->and(slSides(Postings::postingsFor(new WithholdingFacts('wh-1', 'INV-2026-0002', 'br-1', 'acct-2', 'Northwind', '2026-10-08', 9_420, 'WC160'))))->toBe([[RuleKey::CreditableWithholding->value, 9_420, 0], [RuleKey::Receivables->value, 0, 9_420]]);
});

it('splits the VAT out of a prepaid sale only when there is some', function () {
    $facts = fn (int $vat): PrepaidSaleFacts => new PrepaidSaleFacts('pp-1', 'PP-2026-0001', 'br-1', 'acct-1', 'Walk-in', '2026-10-08', 500_000, PaymentMethod::Cash, $vat);

    expect(slSides(Postings::postingsFor($facts(53_571))))->toBe([[RuleKey::CashOnHand->value, 500_000, 0], [RuleKey::UnearnedRevenue->value, 0, 446_429], [RuleKey::OutputVat->value, 0, 53_571]])
        ->and(slSides(Postings::postingsFor($facts(0))))->toBe([[RuleKey::CashOnHand->value, 500_000, 0], [RuleKey::UnearnedRevenue->value, 0, 500_000]]);
});

it('closes a year branch by branch: every profit-and-loss account to zero, the difference to Retained Earnings', function () {
    $balance = fn (string $id, AccountType $type, int $debit, int $credit, string $branch): AccountBalance => new AccountBalance($id, $id, $id, $type, $type === AccountType::Revenue ? Side::Credit : Side::Debit, $debit, $credit, $branch);
    $facts = new YearCloseFacts('fy-1', 2026, 'br-1', [
        $balance('sales', AccountType::Revenue, 0, 900_000, 'br-1'),
        $balance('cogs', AccountType::Expense, 400_000, 0, 'br-1'),
        // The second branch made a loss.
        $balance('sales', AccountType::Revenue, 0, 100_000, 'br-2'),
        $balance('rent', AccountType::Expense, 250_000, 0, 'br-2'),
        $balance('idle', AccountType::Expense, 70, 70, 'br-2'),
    ], '2026-12-31');

    $draft = Postings::postingsFor($facts);
    expect($draft->entryDate)->toBe('2026-12-31')
        ->and($draft->reference)->toBe('FY2026')
        ->and(slSides($draft))->toBe([
            ['sales', 900_000, 0],
            ['cogs', 0, 400_000],
            ['sales', 100_000, 0],
            ['rent', 0, 250_000],
            [RuleKey::RetainedEarnings->value, 0, 500_000],
            [RuleKey::RetainedEarnings->value, 150_000, 0],
        ])
        ->and(array_map(fn (PostingLine $l): string => $l->branchId, array_slice($draft->lines, 4)))->toBe(['br-1', 'br-2'])
        ->and(SubledgerPostings::netIncome($facts))->toBe(350_000);

    // Dated elsewhere only when December was already closed, and the memo says so.
    expect(Postings::postingsFor(new YearCloseFacts('fy-1', 2026, 'br-1', $facts->balances, '2027-01-01'))->memo)->toContain('already closed');
    // A balance-sheet account is never closed.
    expect(fn () => SubledgerPostings::yearClose(new YearCloseFacts('fy-1', 2026, 'br-1', [new AccountBalance('cash', '1000', 'Cash', AccountType::Asset, Side::Debit, 5, 0, 'br-1')], '2026-12-31')))->toThrow(InvalidArgumentException::class);
});

it('knows which rule keys a subledger stands behind, and reads a bank statement', function () {
    $control = array_values(array_map(fn (RuleKey $k): string => $k->value, array_filter(RuleKey::cases(), fn (RuleKey $k): bool => $k->isControl())));
    expect($control)->toBe(['receivables.control', 'inventory.stock', 'payables.control', 'clearing.grir', 'liability.customer_deposits', 'liability.unearned_revenue']);

    // ₱3,831.08 on the statement; ₱3,846.08 cleared before, a ₱15.00 charge ticked now, a ₱1,120.00 cheque still out.
    $statement = new BankStatement(383_108, 384_608, -1_500, -112_000);
    expect($statement->clearedCents())->toBe(383_108)
        ->and($statement->differenceCents())->toBe(0)
        ->and($statement->agrees())->toBeTrue()
        ->and($statement->bookBalanceCents())->toBe(271_108)
        ->and((new BankStatement(383_108, 384_608, 0, -113_500))->differenceCents())->toBe(-1_500);
});

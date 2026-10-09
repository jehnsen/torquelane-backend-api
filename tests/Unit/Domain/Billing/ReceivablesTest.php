<?php

declare(strict_types=1);

use App\Domain\Receivables\Aging;
use App\Domain\Receivables\AgingBucket;
use App\Domain\Receivables\Allocation;
use App\Domain\Receivables\AllocationRefused;
use App\Domain\Receivables\CreditPosition;
use App\Domain\Receivables\OpenInvoice;
use App\Domain\Receivables\Statement;
use App\Domain\Receivables\StatementEntry;
use App\Domain\Shared\WebFormat;
use App\Domain\WorkOrders\LifecycleStage;
use App\Domain\WorkOrders\WorkOrderMachine;
use App\Domain\WorkOrders\WorkOrderStatus;

function openInvoice(string $id, string $due, int $total, int $paid = 0, string $account = 'acc-1', string $issued = '2026-01-01'): OpenInvoice
{
    return new OpenInvoice($id, 'INV-'.$id, $account, $issued, $due, $total, $paid);
}

it('buckets days past due', function (int $days, AgingBucket $bucket) {
    expect(AgingBucket::forDaysPastDue($days))->toBe($bucket);
})->with([
    'not yet due' => [-5, AgingBucket::Current],
    'due today' => [0, AgingBucket::Current],
    'a day late' => [1, AgingBucket::Days1To30],
    '30 days' => [30, AgingBucket::Days1To30],
    '31 days' => [31, AgingBucket::Days31To60],
    '60 days' => [60, AgingBucket::Days31To60],
    '61 days' => [61, AgingBucket::Days61To90],
    '90 days' => [90, AgingBucket::Days61To90],
    '91 days' => [91, AgingBucket::Over90],
]);

it('ages open balances per account as of a date', function () {
    $report = Aging::report([
        openInvoice('a', '2026-10-09', 10_000, 4_000),
        openInvoice('b', '2026-09-30', 5_000),
        openInvoice('c', '2026-06-01', 7_000, 0, 'acc-2'),
        openInvoice('d', '2026-01-01', 9_000, 9_000),
    ], '2026-10-09');

    expect($report['accounts'])->toBe([
        'acc-1' => ['current' => 6_000, 'days_1_30' => 5_000, 'days_31_60' => 0, 'days_61_90' => 0, 'over_90' => 0, 'total' => 11_000],
        'acc-2' => ['current' => 0, 'days_1_30' => 0, 'days_31_60' => 0, 'days_61_90' => 0, 'over_90' => 7_000, 'total' => 7_000],
    ])->and($report['totals'])->toBe(['current' => 6_000, 'days_1_30' => 5_000, 'days_31_60' => 0, 'days_61_90' => 0, 'over_90' => 7_000, 'total' => 18_000]);
});

it('spreads a payment over the oldest due first, the rest is credit', function () {
    $plan = Allocation::oldestFirst(12_000, [
        openInvoice('newer', '2026-10-30', 10_000),
        openInvoice('older', '2026-09-30', 8_000, 3_000),
        openInvoice('oldest', '2026-08-30', 4_000),
    ]);

    expect($plan)->toBe(['oldest' => 4_000, 'older' => 5_000, 'newer' => 3_000])
        ->and(Allocation::oldestFirst(50_000, [openInvoice('only', '2026-08-30', 4_000)]))->toBe(['only' => 4_000]);
});

it('checks a requested spread against balances and what the payment has left', function () {
    $open = ['a' => openInvoice('a', '2026-10-01', 10_000, 2_000), 'b' => openInvoice('b', '2026-10-01', 5_000)];

    expect(Allocation::checked([['invoice_id' => 'a', 'amount_cents' => 8_000], ['invoice_id' => 'b', 'amount_cents' => 1_000]], 9_000, $open))
        ->toBe(['a' => 8_000, 'b' => 1_000]);

    $refusal = function (array $requested, int $available) use ($open): array {
        try {
            Allocation::checked($requested, $available, $open);
        } catch (AllocationRefused $e) {
            return [$e->getMessage(), $e->index];
        }
        throw new LogicException('Expected a refusal.');
    };

    expect($refusal([['invoice_id' => 'a', 'amount_cents' => 8_001]], 50_000))->toBe(['Invoice INV-a has only ₱80.00 outstanding.', 0])
        ->and($refusal([['invoice_id' => 'b', 'amount_cents' => 1], ['invoice_id' => 'b', 'amount_cents' => 1]], 50_000))->toBe(['Each invoice is allocated once per payment.', 1])
        ->and($refusal([['invoice_id' => 'zzz', 'amount_cents' => 1]], 50_000))->toBe(['That invoice is not open for this account.', 0])
        ->and($refusal([['invoice_id' => 'a', 'amount_cents' => 5_000], ['invoice_id' => 'b', 'amount_cents' => 5_000]], 9_999))->toBe(['The allocations add up to ₱100.00; only ₱99.99 is available.', null]);
});

it('builds a statement: brought forward, running balance, closing', function () {
    $entries = [
        new StatementEntry('2026-09-15', StatementEntry::INVOICE, 'i1', 'INV-1', 'Invoice INV-1', 10_000, 0, '1'),
        new StatementEntry('2026-09-20', StatementEntry::PAYMENT, 'p1', 'PAY-1', 'Payment PAY-1', 0, 4_000, '2'),
        new StatementEntry('2026-10-02', StatementEntry::INVOICE, 'i2', 'INV-2', 'Invoice INV-2', 5_000, 0, '3'),
        new StatementEntry('2026-10-05', StatementEntry::PAYMENT, 'p2', 'PAY-2', 'Payment PAY-2', 0, 20_000, '4'),
        new StatementEntry('2026-10-05', StatementEntry::INVOICE_VOID, 'i2', 'INV-2', 'Invoice INV-2 voided', 0, 5_000, '5'),
        new StatementEntry('2026-11-01', StatementEntry::INVOICE, 'i3', 'INV-3', 'After the range', 1, 0, '6'),
    ];

    $statement = Statement::build(array_reverse($entries), '2026-10-01', '2026-10-31');

    expect($statement['opening_balance_cents'])->toBe(6_000)
        ->and(array_map(fn (array $row): array => [$row['entry']->reference, $row['balance_cents']], $statement['entries']))
        ->toBe([['INV-2', 11_000], ['PAY-2', -9_000], ['INV-2', -14_000]])
        ->and($statement['total_charges_cents'])->toBe(5_000)
        ->and($statement['total_credits_cents'])->toBe(25_000)
        ->and($statement['closing_balance_cents'])->toBe(-14_000);
});

it('warns over a credit limit and never without one', function () {
    $over = new CreditPosition(50_000, 60_000, 5_000);
    $under = new CreditPosition(50_000, 60_000, 15_000);

    expect($over->isOverLimit())->toBeTrue()
        ->and($over->availableCents())->toBe(-5_000)
        ->and($under->isOverLimit())->toBeFalse()
        ->and($under->availableCents())->toBe(5_000)
        ->and((new CreditPosition(null, 9_999_999, 0))->isOverLimit())->toBeFalse()
        ->and((new CreditPosition(null, 1, 0))->availableCents())->toBeNull();
});

it('puts a closed job in its billing stage', function () {
    $closed = WorkOrderStatus::Closed;

    expect(WorkOrderMachine::billingStage($closed, false, false))->toBe(LifecycleStage::ReadyForBilling)
        ->and(WorkOrderMachine::billingStage($closed, true, false))->toBe(LifecycleStage::Invoiced)
        ->and(WorkOrderMachine::billingStage($closed, true, true))->toBe(LifecycleStage::Completed)
        // Settled before invoicing existed (collected): completed, never re-billed.
        ->and(WorkOrderMachine::billingStage($closed, false, true))->toBe(LifecycleStage::Completed)
        ->and(WorkOrderMachine::billingStage(WorkOrderStatus::InProgress, true, true))->toBe(LifecycleStage::InProgress)
        // ../web's projection is the same function with no invoice.
        ->and(WorkOrderMachine::lifecycleStage($closed, true))->toBe(LifecycleStage::Completed)
        ->and(WorkOrderMachine::lifecycleStage($closed, false))->toBe(LifecycleStage::ReadyForBilling);
});

it('prints pesos exactly', function () {
    expect(WebFormat::pesos(123_456_789))->toBe('₱1,234,567.89')
        ->and(WebFormat::pesos(5))->toBe('₱0.05')
        ->and(WebFormat::pesos(-7_500))->toBe('-₱75.00')
        ->and(WebFormat::pesos(0))->toBe('₱0.00');
});

<?php

declare(strict_types=1);

use App\Domain\Inventory\CountVariance;
use App\Domain\Inventory\PurchaseUnit;
use App\Domain\Inventory\ReceiptPlan;
use App\Domain\Inventory\ReorderAdvice;
use App\Domain\Inventory\ReorderPlanner;
use App\Domain\Inventory\ShopOrderStatus;
use App\Domain\Inventory\StockAlerts;
use App\Domain\Inventory\WorkOrderIssuePlan;

// ------------------------------------------------------------- purchase units

it('converts purchase units to stock units and costs', function () {
    expect(PurchaseUnit::toStockQuantity('3', '24')->isEqualTo('72'))->toBeTrue()
        ->and(PurchaseUnit::toStockQuantity('1.5', '4')->isEqualTo('6'))->toBeTrue()
        ->and(PurchaseUnit::unitCostCents(120_000, '24'))->toBe(5_000)
        ->and(PurchaseUnit::unitCostCents(10_000, '3'))->toBe(3_333)
        ->and(PurchaseUnit::unitCostCents(10_001, '2'))->toBe(5_001)   // 5000.5 → 5001
        ->and(PurchaseUnit::unitCostCents(777, '1'))->toBe(777);
});

it('rounds the purchase units that cover a stock quantity up', function () {
    expect((string) PurchaseUnit::purchaseUnitsFor('48', '24'))->toBe('2.000')
        ->and((string) PurchaseUnit::purchaseUnitsFor('49', '24'))->toBe('2.042')
        ->and((string) PurchaseUnit::purchaseUnitsFor('5', '1'))->toBe('5.000');
});

it('refuses a purchase unit that holds nothing', function (string $factor) {
    PurchaseUnit::toStockQuantity('1', $factor);
})->with(['0', '-1'])->throws(InvalidArgumentException::class);

// ------------------------------------------------------------ shop order status

it('derives a shop order\'s status from what was ordered and received', function (string $stored, array $lines, string $expected) {
    expect(ShopOrderStatus::derive($stored, $lines)->value)->toBe($expected);
})->with([
    'draft stays draft' => ['draft', [['ordered' => '5', 'received' => '0']], 'draft'],
    'cancelled stays cancelled' => ['cancelled', [['ordered' => '5', 'received' => '5']], 'cancelled'],
    'issued, nothing in' => ['issued', [['ordered' => '5', 'received' => '0'], ['ordered' => '2', 'received' => '0']], 'issued'],
    'one line part-received' => ['issued', [['ordered' => '5', 'received' => '2'], ['ordered' => '2', 'received' => '0']], 'partially_received'],
    'one line complete, the other not' => ['issued', [['ordered' => '5', 'received' => '5'], ['ordered' => '2', 'received' => '0']], 'partially_received'],
    'every line complete' => ['issued', [['ordered' => '5', 'received' => '5'], ['ordered' => '2', 'received' => '2.000']], 'received'],
    'over-complete counts as received' => ['issued', [['ordered' => '5', 'received' => '6']], 'received'],
    'an issued order with no lines is not received' => ['issued', [], 'issued'],
]);

it('says what each shop order status allows', function () {
    expect(ShopOrderStatus::Issued->canReceive())->toBeTrue()
        ->and(ShopOrderStatus::PartiallyReceived->canReceive())->toBeTrue()
        ->and(ShopOrderStatus::Draft->canReceive())->toBeFalse()
        ->and(ShopOrderStatus::Received->canReceive())->toBeFalse()
        ->and(ShopOrderStatus::Cancelled->canReceive())->toBeFalse()
        ->and(ShopOrderStatus::Draft->canCancel())->toBeTrue()
        ->and(ShopOrderStatus::Issued->canCancel())->toBeTrue()
        ->and(ShopOrderStatus::PartiallyReceived->canCancel())->toBeFalse()
        ->and(ShopOrderStatus::Received->canCancel())->toBeFalse();
});

it('limits a receipt to what is still outstanding', function () {
    expect(ReceiptPlan::remaining('10', '4')->isEqualTo('6'))->toBeTrue()
        ->and(ReceiptPlan::remaining('10', '12')->isZero())->toBeTrue()
        ->and(ReceiptPlan::fits('10', '4', '6'))->toBeTrue()
        ->and(ReceiptPlan::fits('10', '4', '6.001'))->toBeFalse()
        ->and(ReceiptPlan::fits('10', '10', '0.001'))->toBeFalse();
});

// ----------------------------------------------------------- work-order issues

it('issues the target of a line that has none out yet', function () {
    $deltas = WorkOrderIssuePlan::deltas(['l1' => ['item_id' => 'i1', 'target' => '2']], []);

    expect($deltas)->toHaveCount(1)
        ->and($deltas[0]['line_id'])->toBe('l1')
        ->and($deltas[0]['item_id'])->toBe('i1')
        ->and($deltas[0]['quantity']->isEqualTo('-2'))->toBeTrue();
});

it('plans nothing for an order that is already in step', function () {
    expect(WorkOrderIssuePlan::deltas(['l1' => ['item_id' => 'i1', 'target' => '2']], ['l1' => ['item_id' => 'i1', 'quantity' => '2.000']]))->toBe([]);
});

it('plans only the difference when a quantity changes, as a compensating move', function () {
    $more = WorkOrderIssuePlan::deltas(['l1' => ['item_id' => 'i1', 'target' => '5']], ['l1' => ['item_id' => 'i1', 'quantity' => '2']]);
    $less = WorkOrderIssuePlan::deltas(['l1' => ['item_id' => 'i1', 'target' => '1']], ['l1' => ['item_id' => 'i1', 'quantity' => '2']]);

    expect($more[0]['quantity']->isEqualTo('-3'))->toBeTrue()->and($less[0]['quantity']->isEqualTo('1'))->toBeTrue();
});

it('returns everything for a target of zero (cancelled, or not yet approved)', function () {
    $deltas = WorkOrderIssuePlan::deltas(['l1' => ['item_id' => 'i1', 'target' => '0']], ['l1' => ['item_id' => 'i1', 'quantity' => '3']]);

    expect($deltas[0]['quantity']->isEqualTo('3'))->toBeTrue();
});

it('returns what a vanished line had out', function () {
    $deltas = WorkOrderIssuePlan::deltas([], ['gone' => ['item_id' => 'i9', 'quantity' => '4']]);

    expect($deltas)->toHaveCount(1)->and($deltas[0]['line_id'])->toBe('gone')->and($deltas[0]['item_id'])->toBe('i9')->and($deltas[0]['quantity']->isEqualTo('4'))->toBeTrue();
});

it('plans every line independently', function () {
    $deltas = WorkOrderIssuePlan::deltas(
        ['a' => ['item_id' => 'ia', 'target' => '1'], 'b' => ['item_id' => 'ib', 'target' => '2'], 'c' => ['item_id' => 'ic', 'target' => '0']],
        ['a' => ['item_id' => 'ia', 'quantity' => '1'], 'b' => ['item_id' => 'ib', 'quantity' => '0']],
    );

    expect(array_column($deltas, 'line_id'))->toBe(['b']);
});

// -------------------------------------------------------------------- counts

it('finds a count\'s variance as counted − expected', function () {
    expect(CountVariance::between('10', '7.5')->isEqualTo('-2.5'))->toBeTrue()
        ->and(CountVariance::between('0', '3')->isEqualTo('3'))->toBeTrue()
        ->and(CountVariance::between('4', '4.000')->isZero())->toBeTrue();
});

// -------------------------------------------------------------------- alerts

it('derives low-stock alerts from the reorder point, worst first, with stable ids', function () {
    $alerts = StockAlerts::derive([
        ['item_id' => 'b', 'location_id' => 'x', 'on_hand' => '3', 'reorder_point' => '5'],
        ['item_id' => 'a', 'location_id' => 'x', 'on_hand' => '5', 'reorder_point' => '5'],
        ['item_id' => 'c', 'location_id' => 'x', 'on_hand' => '0', 'reorder_point' => '2'],
        ['item_id' => 'd', 'location_id' => 'x', 'on_hand' => '-1', 'reorder_point' => '2'],
        ['item_id' => 'e', 'location_id' => 'x', 'on_hand' => '1', 'reorder_point' => null],
        ['item_id' => 'f', 'location_id' => 'x', 'on_hand' => '6', 'reorder_point' => '5'],
    ]);

    expect(array_column($alerts, 'id'))->toBe(['stock:c:x', 'stock:d:x', 'stock:a:x', 'stock:b:x'])
        ->and(array_column($alerts, 'severity'))->toBe(['critical', 'critical', 'warning', 'warning']);
});

// ------------------------------------------------------------------- reorder

it('advises nothing for an item that is well stocked', function () {
    $advice = ReorderPlanner::advise('20', '0', '4', '5', '10');

    expect($advice->reason)->toBe(ReorderAdvice::OK)->and($advice->needsOrder())->toBeFalse()
        ->and($advice->projected->isEqualTo('16'))->toBeTrue();
});

it('flags an item under its reorder point and suggests the reorder quantity or the gap, whichever is larger', function () {
    $small = ReorderPlanner::advise('3', '0', '0', '5', '10');     // gap 2 < reorder qty 10
    $big = ReorderPlanner::advise('1', '0', '0', '30', '10');      // gap 29 > 10

    expect($small->reason)->toBe(ReorderAdvice::BELOW_REORDER_POINT)->and($small->suggestedStockQuantity->isEqualTo('10'))->toBeTrue()
        ->and($big->reason)->toBe(ReorderAdvice::BELOW_REORDER_POINT)->and($big->suggestedStockQuantity->isEqualTo('29'))->toBeTrue();
});

it('counts what is already on order', function () {
    $covered = ReorderPlanner::advise('2', '10', '0', '5', '10');

    expect($covered->reason)->toBe(ReorderAdvice::OK)->and($covered->needsOrder())->toBeFalse();
});

it('reports a stockout, and buys only what the supply on order does not cover', function () {
    $none = ReorderPlanner::advise('0', '0', '0', '4', '6');
    $coming = ReorderPlanner::advise('0', '6', '0', '4', '6');

    expect($none->reason)->toBe(ReorderAdvice::STOCKOUT)->and($none->suggestedStockQuantity->isEqualTo('6'))->toBeTrue()
        ->and($coming->reason)->toBe(ReorderAdvice::STOCKOUT)->and($coming->needsOrder())->toBeFalse();
});

it('has a forecast shortfall where demand will outrun the shelf', function () {
    $advice = ReorderPlanner::advise('10', '0', '14', '2', '5');

    expect($advice->reason)->toBe(ReorderAdvice::FORECAST_SHORTFALL)
        ->and($advice->projected->isEqualTo('-4'))->toBeTrue()
        ->and($advice->suggestedStockQuantity->isEqualTo('6'))->toBeTrue();   // 2 − (−4) = 6 > reorder qty 5
});

it('uses the forecast alone where no reorder point is set', function () {
    $short = ReorderPlanner::advise('3', '0', '5', null, null);
    $fine = ReorderPlanner::advise('8', '0', '5', null, null);
    $idle = ReorderPlanner::advise('0', '0', '0', null, null);

    expect($short->reason)->toBe(ReorderAdvice::FORECAST_SHORTFALL)->and($short->suggestedStockQuantity->isEqualTo('2'))->toBeTrue()
        ->and($fine->reason)->toBe(ReorderAdvice::OK)
        ->and($idle->reason)->toBe(ReorderAdvice::OK);
});

it('turns the suggestion into purchase units', function () {
    $advice = ReorderPlanner::advise('0', '0', '0', '10', '48', '24');

    expect($advice->suggestedStockQuantity->isEqualTo('48'))->toBeTrue()->and((string) $advice->suggestedPurchaseQuantity)->toBe('2.000');
});

it('keeps decimals exact', function () {
    expect(ReorderPlanner::advise('0.5', '0', '0', '1.25', null)->suggestedStockQuantity->isEqualTo('0.75'))->toBeTrue();
});

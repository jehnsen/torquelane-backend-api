<?php

declare(strict_types=1);

use App\Domain\Inventory\InsufficientStock;
use App\Domain\Inventory\MoveRequest;
use App\Domain\Inventory\MoveType;
use App\Domain\Inventory\NegativeStockPolicy;
use App\Domain\Inventory\StockLedger;
use App\Domain\Inventory\StockState;
use Brick\Math\BigDecimal;

function ledgerMove(MoveType $type, string $quantity, ?int $cost = null): MoveRequest
{
    return new MoveRequest($type, $quantity, $cost);
}

function ledgerState(string $onHand, int $avg): StockState
{
    return new StockState($onHand, $avg);
}

// ------------------------------------------------------------ inbound, costed

it('starts an empty balance at the first receipt\'s cost', function () {
    $outcome = StockLedger::applyMove(StockState::empty(), ledgerMove(MoveType::Receipt, '10', 12_500));

    expect((string) $outcome->after->onHand)->toBe('10')
        ->and($outcome->after->avgCostCents)->toBe(12_500)
        ->and($outcome->unitCostCents)->toBe(12_500)
        ->and($outcome->negative)->toBeFalse();
});

it('blends a receipt into the average, weighted by quantity', function () {
    // 10 @ ₱100.00 + 30 @ ₱120.00 = 4,600 ÷ 40 → ₱115.00
    $outcome = StockLedger::applyMove(ledgerState('10', 10_000), ledgerMove(MoveType::Receipt, '30', 12_000));

    expect((string) $outcome->after->onHand)->toBe('40')
        ->and($outcome->after->avgCostCents)->toBe(11_500);
});

it('rounds the blended average half-up to a centavo', function () {
    // 1 @ 100 + 2 @ 101 = 302 ÷ 3 = 100.667 → 101;  1 @ 100 + 1 @ 101 = 201 ÷ 2 = 100.5 → 101;  2 @ 100 + 1 @ 101 = 301 ÷ 3 = 100.33 → 100
    expect(StockLedger::applyMove(ledgerState('1', 100), ledgerMove(MoveType::Receipt, '2', 101))->after->avgCostCents)->toBe(101)
        ->and(StockLedger::applyMove(ledgerState('1', 100), ledgerMove(MoveType::Receipt, '1', 101))->after->avgCostCents)->toBe(101)
        ->and(StockLedger::applyMove(ledgerState('2', 100), ledgerMove(MoveType::Receipt, '1', 101))->after->avgCostCents)->toBe(100);
});

it('rounds once, exactly, not through an intermediate scale', function () {
    // 3 @ 1 + 7 @ 2 = 17 ÷ 10 = 1.7 → 2. 999,999 @ 1 + 1 @ 1,000,000 = 1,999,999 ÷ 1,000,000 = 1.999999 → 2.
    expect(StockLedger::applyMove(ledgerState('3', 1), ledgerMove(MoveType::Receipt, '7', 2))->after->avgCostCents)->toBe(2)
        ->and(StockLedger::applyMove(ledgerState('999999', 1), ledgerMove(MoveType::Receipt, '1', 1_000_000))->after->avgCostCents)->toBe(2);
});

it('handles fractional quantities', function () {
    // 2.5 L @ ₱80.00 + 7.5 L @ ₱88.00 = 200 + 660 = 860 ÷ 10 = ₱86.00
    $outcome = StockLedger::applyMove(ledgerState('2.5', 8_000), ledgerMove(MoveType::Receipt, '7.5', 8_800));

    expect($outcome->after->onHand->isEqualTo('10'))->toBeTrue()
        ->and($outcome->after->avgCostCents)->toBe(8_600);
});

it('treats every costed inbound type the same way', function (MoveType $type) {
    $outcome = StockLedger::applyMove(ledgerState('10', 100), ledgerMove($type, '10', 200));

    expect($outcome->after->avgCostCents)->toBe(150)->and($outcome->unitCostCents)->toBe(200);
})->with([MoveType::Opening, MoveType::Receipt, MoveType::TransferIn]);

it('refuses a costed inbound type with no cost', function (MoveType $type) {
    StockLedger::applyMove(ledgerState('10', 100), ledgerMove($type, '5'));
})->with([MoveType::Opening, MoveType::Receipt, MoveType::TransferIn])->throws(InvalidArgumentException::class, 'must say what the goods cost');

it('brings returns and positive adjustments in at the average unless told a cost', function () {
    $return = StockLedger::applyMove(ledgerState('10', 150), ledgerMove(MoveType::Return, '2'));
    $found = StockLedger::applyMove(ledgerState('10', 150), ledgerMove(MoveType::Adjustment, '4', 300));

    expect($return->unitCostCents)->toBe(150)->and($return->after->avgCostCents)->toBe(150)
        ->and($found->unitCostCents)->toBe(300)->and($found->after->avgCostCents)->toBe(193);   // (1,500 + 1,200) ÷ 14 = 192.86
});

it('does not blend onto a negative balance: the average resets once stock is back above zero', function () {
    // Short by 3 @ ₱100, then 10 arrive at ₱120: 7 on hand, all of it at ₱120.
    $outcome = StockLedger::applyMove(ledgerState('-3', 10_000), ledgerMove(MoveType::Receipt, '10', 12_000));

    expect($outcome->after->onHand->isEqualTo('7'))->toBeTrue()->and($outcome->after->avgCostCents)->toBe(12_000);
});

it('keeps the average while a receipt still leaves the balance short', function () {
    $outcome = StockLedger::applyMove(ledgerState('-10', 10_000), ledgerMove(MoveType::Receipt, '4', 12_000));

    expect($outcome->after->onHand->isEqualTo('-6'))->toBeTrue()->and($outcome->after->avgCostCents)->toBe(10_000)->and($outcome->negative)->toBeFalse();
});

it('keeps the average when a receipt exactly clears a shortage', function () {
    $outcome = StockLedger::applyMove(ledgerState('-4', 10_000), ledgerMove(MoveType::Receipt, '4', 12_000));

    expect($outcome->after->onHand->isZero())->toBeTrue()->and($outcome->after->avgCostCents)->toBe(10_000);
});

// ----------------------------------------------------------------- outbound

it('issues at the average and leaves the average alone', function () {
    $outcome = StockLedger::applyMove(ledgerState('10', 11_500), ledgerMove(MoveType::Issue, '-4', 99_999));

    expect($outcome->after->onHand->isEqualTo('6'))->toBeTrue()
        ->and($outcome->after->avgCostCents)->toBe(11_500)
        ->and($outcome->unitCostCents)->toBe(11_500)
        ->and($outcome->negative)->toBeFalse();
});

it('keeps the last average when a balance empties', function () {
    $outcome = StockLedger::applyMove(ledgerState('4', 11_500), ledgerMove(MoveType::Issue, '-4'));

    expect($outcome->after->onHand->isZero())->toBeTrue()->and($outcome->after->avgCostCents)->toBe(11_500)->and($outcome->negative)->toBeFalse();
});

it('allows and flags a move past zero by default', function () {
    $outcome = StockLedger::applyMove(ledgerState('2', 5_000), ledgerMove(MoveType::Issue, '-5'));

    expect($outcome->after->onHand->isEqualTo('-3'))->toBeTrue()->and($outcome->negative)->toBeTrue()->and($outcome->unitCostCents)->toBe(5_000);
});

it('flags only the moves that leave the balance negative', function () {
    $short = ledgerState('-3', 5_000);

    expect(StockLedger::applyMove($short, ledgerMove(MoveType::Issue, '-1'))->negative)->toBeTrue()
        ->and(StockLedger::applyMove($short, ledgerMove(MoveType::Return, '1'))->negative)->toBeFalse();
});

it('blocks a move past zero when the branch says so', function () {
    StockLedger::applyMove(ledgerState('2', 5_000), ledgerMove(MoveType::Issue, '-5'), NegativeStockPolicy::Block);
})->throws(InsufficientStock::class, 'Only 2 on hand; 5 requested.');

it('lets the blocking policy take a balance exactly to zero', function () {
    $outcome = StockLedger::applyMove(ledgerState('5', 5_000), ledgerMove(MoveType::Issue, '-5'), NegativeStockPolicy::Block);

    expect($outcome->after->onHand->isZero())->toBeTrue();
});

it('never blocks inbound goods', function () {
    $outcome = StockLedger::applyMove(ledgerState('-5', 5_000), ledgerMove(MoveType::Receipt, '1', 6_000), NegativeStockPolicy::Block);

    expect($outcome->after->onHand->isEqualTo('-4'))->toBeTrue();
});

it('blocks a negative return (goods back to the supplier) like any other outbound move', function () {
    StockLedger::applyMove(ledgerState('1', 5_000), ledgerMove(MoveType::Return, '-3'), NegativeStockPolicy::Block);
})->throws(InsufficientStock::class);

// ------------------------------------------------------------ move validation

it('refuses a move of nothing', function () {
    ledgerMove(MoveType::Receipt, '0', 100);
})->throws(InvalidArgumentException::class, 'moves something');

it('keeps each type to its direction', function (MoveType $type, string $quantity) {
    ledgerMove($type, $quantity, 100);
})->with([
    [MoveType::Receipt, '-1'], [MoveType::Opening, '-1'], [MoveType::TransferIn, '-1'],
    [MoveType::Issue, '1'], [MoveType::TransferOut, '1'], [MoveType::Consumption, '1'],
])->throws(InvalidArgumentException::class);

it('lets returns and adjustments go either way', function () {
    expect(ledgerMove(MoveType::Return, '1')->isInbound())->toBeTrue()
        ->and(ledgerMove(MoveType::Return, '-1')->isInbound())->toBeFalse()
        ->and(ledgerMove(MoveType::Adjustment, '-0.001')->isInbound())->toBeFalse();
});

it('refuses more than three decimals and negative costs', function () {
    expect(fn () => ledgerMove(MoveType::Receipt, '1.0005', 100))->toThrow(InvalidArgumentException::class, 'three decimals')
        ->and(fn () => ledgerMove(MoveType::Receipt, '1.500000', 100))->not->toThrow(InvalidArgumentException::class)
        ->and(fn () => ledgerMove(MoveType::Receipt, '1', -1))->toThrow(InvalidArgumentException::class, 'negative');
});

// ----------------------------------------------------------------- valuation

it('values a balance at on hand × average, rounded half-up once', function () {
    expect(StockLedger::valuation(ledgerState('10', 11_500)))->toBe(115_000)
        ->and(StockLedger::valuation(ledgerState('0.5', 101)))->toBe(51)       // 50.5 → 51
        ->and(StockLedger::valuation(ledgerState('2.333', 300)))->toBe(700)    // 699.9 → 700
        ->and(StockLedger::valuation(ledgerState('0', 500)))->toBe(0)
        ->and(StockLedger::valuation(ledgerState('-2', 500)))->toBe(-1_000);
});

it('sums a total exactly and rounds it once, not row by row', function () {
    // Each row is 50.5 centavos: rounded row by row that is 51 + 51 = 102; summed exactly it is 101.
    $rows = [ledgerState('0.5', 101), ledgerState('0.5', 101)];

    expect(StockLedger::totalValuation($rows))->toBe(101)
        ->and(StockLedger::valuation($rows[0]) + StockLedger::valuation($rows[1]))->toBe(102)
        ->and(StockLedger::totalValuation([]))->toBe(0);
});

it('values a move at its quantity × its unit cost, whichever way it goes', function () {
    expect(StockLedger::moveValue('-4', 11_500))->toBe(46_000)
        ->and(StockLedger::moveValue('2.5', 3))->toBe(8);   // 7.5 → 8
});

// ----------------------------------------------------------------- invariants

it('keeps on hand equal to the sum of the quantities, however the moves come', function () {
    mt_srand(20261009);
    $state = StockState::empty();
    $sum = BigDecimal::zero();
    $types = [MoveType::Receipt, MoveType::Issue, MoveType::Adjustment, MoveType::Return, MoveType::Consumption, MoveType::TransferIn, MoveType::TransferOut];

    for ($i = 0; $i < 500; $i++) {
        $type = $types[mt_rand(0, count($types) - 1)];
        $quantity = number_format(mt_rand(1, 20_000) / 1000, 3, '.', '');
        if ($type->direction() < 0 || ($type->direction() === 0 && mt_rand(0, 1) === 0)) {
            $quantity = '-'.$quantity;
        }
        $request = ledgerMove($type, $quantity, mt_rand(0, 50_000));
        $outcome = StockLedger::applyMove($state, $request);
        $state = $outcome->after;
        $sum = $sum->plus($quantity);

        expect($outcome->after->avgCostCents)->toBeGreaterThanOrEqual(0)
            ->and($outcome->negative)->toBe($state->onHand->isNegative() && ! $request->isInbound());
    }

    expect($state->onHand->isEqualTo($sum))->toBeTrue();
});

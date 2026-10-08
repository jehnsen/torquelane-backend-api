<?php

declare(strict_types=1);

use App\Casts\DecimalCast;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;

beforeEach(function () {
    $this->cast = new DecimalCast;
    $this->model = new class extends Model {};
});

it('reads numeric(14,3) as BigDecimal at scale 3', function () {
    $value = $this->cast->get($this->model, 'quantity', '2.500', []);

    expect($value)->toBeInstanceOf(BigDecimal::class)
        ->and((string) $value)->toBe('2.500');
});

it('writes ints, strings and BigDecimals at the column scale', function (mixed $input, string $stored) {
    expect($this->cast->set($this->model, 'quantity', $input, []))->toBe(['quantity' => $stored]);
})->with([
    'int' => [2, '2.000'],
    'string' => ['1.5', '1.500'],
    'BigDecimal' => [BigDecimal::of('0.125'), '0.125'],
]);

it('rejects floats', function () {
    $this->cast->set($this->model, 'quantity', 1.5, []);
})->throws(InvalidArgumentException::class, 'got float');

it('refuses to round a value with more decimals than the column holds', function () {
    $this->cast->set($this->model, 'quantity', '1.2345', []);
})->throws(InvalidArgumentException::class, 'does not fit without rounding');

it('serialises to a decimal string, never a float', function () {
    expect($this->cast->serialize($this->model, 'quantity', BigDecimal::of('2.500'), []))->toBe('2.500');
});

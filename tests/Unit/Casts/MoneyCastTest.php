<?php

declare(strict_types=1);

use App\Casts\MoneyCast;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;

beforeEach(function () {
    $this->cast = new MoneyCast;
    $this->model = new class extends Model {};
});

it('reads integer minor units as PHP Money', function (int|string $stored) {
    $money = $this->cast->get($this->model, 'total_cents', $stored, []);

    expect($money)->toBeInstanceOf(Money::class)
        ->and((string) $money)->toBe('PHP 1234.56');
})->with([123456, '123456']);

it('writes Money and integers as minor units', function () {
    expect($this->cast->set($this->model, 'total_cents', Money::of('1234.56', 'PHP'), []))->toBe(['total_cents' => 123456])
        ->and($this->cast->set($this->model, 'total_cents', 500, []))->toBe(['total_cents' => 500])
        ->and($this->cast->set($this->model, 'total_cents', null, []))->toBe(['total_cents' => null]);
});

it('rejects floats and decimal strings', function (mixed $value) {
    $this->cast->set($this->model, 'total_cents', $value, []);
})->with([12.34, '12.34', '1234'])->throws(InvalidArgumentException::class);

it('rejects Money in another currency', function () {
    $this->cast->set($this->model, 'total_cents', Money::of(10, 'USD'), []);
})->throws(InvalidArgumentException::class, 'is PHP; got USD');

it('honours a currency argument', function () {
    expect((string) (new MoneyCast('USD'))->get($this->model, 'total_cents', 1999, []))->toBe('USD 19.99');
});

it('only applies to *_cents columns', function () {
    $this->cast->get($this->model, 'total', 100, []);
})->throws(LogicException::class);

it('serialises to integer cents for JSON', function () {
    expect($this->cast->serialize($this->model, 'total_cents', Money::ofMinor(123456, 'PHP'), []))->toBe(123456);
});

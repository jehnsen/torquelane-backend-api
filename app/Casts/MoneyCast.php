<?php

declare(strict_types=1);

namespace App\Casts;

use Brick\Math\BigInteger;
use Brick\Money\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;

/**
 * Maps a `*_cents` bigint column to Brick\Money\Money (R6).
 *
 *     'subtotal_cents' => MoneyCast::class,          // PHP
 *     'subtotal_cents' => MoneyCast::class.':USD',
 *
 * Writes accept Money (in the column's currency) or integer minor units.
 * A float or a decimal string is rejected outright — there is no lossless way
 * to read one as cents. Serialises to integer cents, never a decimal.
 *
 * @implements CastsAttributes<Money, mixed>
 */
final class MoneyCast implements CastsAttributes, SerializesCastableAttributes
{
    public function __construct(private readonly string $currency = 'PHP') {}

    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        $this->assertColumnName($key);

        if ($value === null) {
            return null;
        }

        if (! is_int($value) && ! (is_string($value) && preg_match('/\A-?\d+\z/', $value) === 1)) {
            throw new InvalidArgumentException("Column [{$key}] holds a non-integer amount.");
        }

        return Money::ofMinor(BigInteger::of($value), $this->currency);
    }

    /**
     * @return array<string, int|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        $this->assertColumnName($key);

        if ($value === null) {
            return [$key => null];
        }

        if ($value instanceof Money) {
            if ($value->getCurrency()->getCurrencyCode() !== $this->currency) {
                throw new InvalidArgumentException(sprintf(
                    'Column [%s] is %s; got %s.',
                    $key,
                    $this->currency,
                    $value->getCurrency()->getCurrencyCode(),
                ));
            }

            return [$key => $value->getMinorAmount()->toInt()];
        }

        if (is_int($value)) {
            return [$key => $value];
        }

        throw new InvalidArgumentException(sprintf(
            'Column [%s] accepts Money or integer cents; got %s.',
            $key,
            get_debug_type($value),
        ));
    }

    public function serialize(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        return $value instanceof Money ? $value->getMinorAmount()->toInt() : null;
    }

    private function assertColumnName(string $key): void
    {
        if (! str_ends_with($key, '_cents')) {
            throw new LogicException("MoneyCast is for *_cents columns; [{$key}] is not one.");
        }
    }
}

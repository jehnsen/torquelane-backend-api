<?php

declare(strict_types=1);

namespace App\Casts;

use App\Database\SchemaMacros;
use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Maps a numeric(14,3) quantity or rate column to Brick\Math\BigDecimal.
 *
 * Writes accept BigNumber, int, or a numeric string. Floats are rejected, and
 * so is a value with more decimals than the column holds: rounding silently
 * at the storage boundary would be a per-line rounding (R6). Serialises to a
 * decimal string ("1.500") so JSON never carries a float.
 *
 * @implements CastsAttributes<BigDecimal, mixed>
 */
final class DecimalCast implements CastsAttributes, SerializesCastableAttributes
{
    /** @var int<0, max> */
    private readonly int $scale;

    /**
     * @param  string|int  $scale  a string when given as a cast argument ('quantity' => DecimalCast::class.':2')
     */
    public function __construct(string|int $scale = SchemaMacros::DECIMAL_SCALE)
    {
        $scale = filter_var($scale, FILTER_VALIDATE_INT);
        if ($scale === false || $scale < 0) {
            throw new InvalidArgumentException('DecimalCast scale must be a non-negative integer.');
        }

        $this->scale = $scale;
    }

    public function get(Model $model, string $key, mixed $value, array $attributes): ?BigDecimal
    {
        if ($value === null) {
            return null;
        }

        if (! is_int($value) && ! is_string($value)) {
            throw new InvalidArgumentException("Column [{$key}] holds a non-decimal value.");
        }

        return BigDecimal::of($value)->toScale($this->scale, RoundingMode::Unnecessary);
    }

    /**
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        if (! $value instanceof BigNumber && ! is_int($value) && ! is_string($value)) {
            throw new InvalidArgumentException(sprintf(
                'Column [%s] accepts BigDecimal, int or a numeric string; got %s.',
                $key,
                get_debug_type($value),
            ));
        }

        try {
            $decimal = BigDecimal::of($value)->toScale($this->scale, RoundingMode::Unnecessary);
        } catch (MathException $e) {
            throw new InvalidArgumentException(
                "Column [{$key}] holds {$this->scale} decimal places; [{$value}] does not fit without rounding.",
                previous: $e,
            );
        }

        return [$key => (string) $decimal];
    }

    public function serialize(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value instanceof BigDecimal ? (string) $value : null;
    }
}

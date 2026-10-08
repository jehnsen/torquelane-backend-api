<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use JsonException;
use stdClass;

/**
 * A nullable jsonb OBJECT column (`approval_threshold_overrides`,
 * `theme_tokens`). Laravel's `array` cast writes an empty PHP array as `[]`,
 * a JSON array, which the column's CHECK rejects and which would blur "an
 * empty override" into "a list". This writes `{}`, keeps NULL as NULL, and
 * reads both back as PHP arrays.
 *
 * @implements CastsAttributes<array<string, mixed>|null, mixed>
 */
final class JsonObject implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw new InvalidArgumentException("Column [{$key}] holds a non-JSON value.");
        }

        $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new InvalidArgumentException("Column [{$key}] holds JSON that is not an object.");
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|null>
     *
     * @throws JsonException
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException("Column [{$key}] takes a JSON object (a string-keyed array) or null.");
        }

        return [$key => json_encode($value === [] ? new stdClass : $value, JSON_THROW_ON_ERROR)];
    }
}

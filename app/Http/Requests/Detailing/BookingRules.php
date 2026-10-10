<?php

declare(strict_types=1);

namespace App\Http\Requests\Detailing;

use App\Domain\Detailing\SizeClass;
use App\Domain\Detailing\SlotGrid;
use App\Http\Requests\Input;
use Illuminate\Validation\Rule;

/** Rules and readers the detailing requests share. */
final class BookingRules
{
    /**
     * @return array<string, list<mixed>>
     */
    public static function addons(): array
    {
        return [
            'addons' => ['sometimes', 'array', 'max:30'],
            'addons.*.addon_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'addons.*.name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'addons.*.quantity' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:999', 'regex:/^\d+(\.\d{1,3})?$/'],
            'addons.*.unit_price_cents' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000'],
        ];
    }

    /**
     * @return list<array{addon_id?: string|null, name?: string|null, quantity?: string|null, unit_price_cents?: int|null}>
     */
    public static function addonRows(mixed $value): array
    {
        $rows = [];
        foreach (Input::rows($value) as $row) {
            $rows[] = [
                'addon_id' => isset($row['addon_id']) && is_string($row['addon_id']) ? Input::id($row['addon_id']) : null,
                'name' => Input::text($row['name'] ?? null),
                'quantity' => isset($row['quantity']) ? Input::string($row['quantity']) : null,
                'unit_price_cents' => isset($row['unit_price_cents']) ? Input::int($row['unit_price_cents']) : null,
            ];
        }

        return $rows;
    }

    /**
     * @return list<mixed>
     */
    public static function size(string $presence = 'required'): array
    {
        return [$presence, 'string', Rule::enum(SizeClass::class)];
    }

    /**
     * A time of day, 24-hour "HH:MM".
     *
     * @return list<mixed>
     */
    public static function time(string $presence = 'required'): array
    {
        return [$presence, 'string', 'date_format:H:i'];
    }

    public static function minutes(mixed $time): int
    {
        return SlotGrid::parseTime(Input::string($time)) ?? 0;
    }

    /**
     * @return list<string>
     */
    public static function ids(mixed $value): array
    {
        return is_array($value) ? array_values(array_map(Input::id(...), $value)) : [];
    }
}

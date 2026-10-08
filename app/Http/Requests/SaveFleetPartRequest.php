<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ServiceTask;
use Illuminate\Validation\Rule;

/**
 * A customer's spare part. `customer_account_id` (staff; a portal user's own
 * account otherwise) and `current_stock` are accepted on create only: after
 * that, stock changes by receiving purchase orders. `usages` replaces the
 * part's task links when given.
 */
final class SaveFleetPartRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $create = $this->isMethod('POST');

        return [
            'customer_account_id' => $create ? [Rule::requiredIf(fn (): bool => $this->tenant()?->isStaff() ?? false), 'string', 'ulid'] : ['prohibited'],
            'sku' => [$this->presence(), 'string', 'max:64'],
            'name' => [$this->presence(), 'string', 'max:255'],
            'category' => ['sometimes', 'string', Rule::in([...ServiceTask::CATEGORIES, 'other'])],
            'unit' => ['sometimes', 'string', 'max:16'],
            'unit_cost_cents' => [$this->presence(), 'integer', 'min:0', 'max:100000000000'],
            'current_stock' => $create ? ['sometimes', 'integer', 'min:0', 'max:1000000'] : ['prohibited'],
            'reorder_point' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'preferred_vendor' => ['sometimes', 'string', 'max:255'],
            'lead_time_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'is_active' => ['sometimes', 'boolean'],
            'usages' => ['sometimes', 'array', 'max:50'],
            'usages.*.service_task_id' => ['required', 'string', 'ulid', 'distinct'],
            'usages.*.quantity_per_service' => ['required', 'integer', 'min:1', 'max:1000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function partAttributes(): array
    {
        $attributes = [];
        foreach ($this->safe()->except(['customer_account_id', 'usages']) as $key => $value) {
            if (is_string($key)) {
                $attributes[$key] = in_array($key, ['sku', 'name', 'unit', 'preferred_vendor'], true) && is_string($value) ? trim($value) : $value;
            }
        }

        return $attributes;
    }

    /**
     * @return list<array{service_task_id: string, quantity_per_service: int}>|null null: not given
     */
    public function usages(): ?array
    {
        if (! $this->has('usages')) {
            return null;
        }

        return array_values(array_map(fn (mixed $usage): array => [
            'service_task_id' => is_array($usage) && is_string($usage['service_task_id'] ?? null) ? mb_strtolower($usage['service_task_id']) : '',
            'quantity_per_service' => is_array($usage) && is_int($usage['quantity_per_service'] ?? null) ? $usage['quantity_per_service'] : (int) (is_array($usage) && is_numeric($usage['quantity_per_service'] ?? null) ? $usage['quantity_per_service'] : 0),
        ], $this->array('usages')));
    }

    public function accountId(): ?string
    {
        return $this->filled('customer_account_id') ? $this->string('customer_account_id')->lower()->toString() : null;
    }
}

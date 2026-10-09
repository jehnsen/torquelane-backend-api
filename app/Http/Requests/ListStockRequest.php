<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Inventory\ItemType;
use Illuminate\Validation\Rule;

/** Stock on hand, and the low-stock alerts (which take only the branch). */
final class ListStockRequest extends PaginatedRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'branch_id' => ['sometimes', 'string', 'ulid'],
            'location_id' => ['sometimes', 'string', 'ulid'],
            'q' => ['sometimes', 'string', 'max:100'],
            'item_type' => ['sometimes', Rule::enum(ItemType::class)],
            'hide_zero' => ['sometimes', 'boolean'],
            'low' => ['sometimes', 'boolean'],
        ]);
    }

    /**
     * @return array{branch_id?: string, location_id?: string, q?: string, item_type?: string, hide_zero?: bool, low?: bool}
     */
    public function filters(): array
    {
        $filters = [];
        foreach (['branch_id', 'location_id'] as $key) {
            if ($this->filled($key)) {
                $filters[$key] = $this->string($key)->lower()->toString();
            }
        }
        foreach (['q', 'item_type'] as $key) {
            if ($this->filled($key)) {
                $filters[$key] = trim($this->string($key)->toString());
            }
        }
        foreach (['hide_zero', 'low'] as $key) {
            if ($this->has($key)) {
                $filters[$key] = $this->boolean($key);
            }
        }

        return $filters;
    }
}

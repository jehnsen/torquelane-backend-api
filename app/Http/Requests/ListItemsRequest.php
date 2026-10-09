<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Inventory\ItemType;
use Illuminate\Validation\Rule;

final class ListItemsRequest extends PaginatedRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'q' => ['sometimes', 'string', 'max:100'],
            'item_type' => ['sometimes', Rule::enum(ItemType::class)],
            'category' => ['sometimes', 'string', 'max:64'],
            'is_stocked' => ['sometimes', 'boolean'],
            'include_inactive' => ['sometimes', 'boolean'],
        ]);
    }

    /**
     * @return array{q?: string, item_type?: string, category?: string, is_stocked?: bool, include_inactive?: bool}
     */
    public function filters(): array
    {
        $filters = [];
        foreach (['q', 'item_type', 'category'] as $key) {
            if ($this->filled($key)) {
                $filters[$key] = trim($this->string($key)->toString());
            }
        }
        foreach (['is_stocked', 'include_inactive'] as $key) {
            if ($this->has($key)) {
                $filters[$key] = $this->boolean($key);
            }
        }

        return $filters;
    }
}

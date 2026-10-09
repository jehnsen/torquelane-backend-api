<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Inventory\MoveType;
use App\Domain\Inventory\StockSource;
use Illuminate\Validation\Rule;

final class ListMovesRequest extends PaginatedRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'branch_id' => ['sometimes', 'string', 'ulid'],
            'location_id' => ['sometimes', 'string', 'ulid'],
            'item_id' => ['sometimes', 'string', 'ulid'],
            'move_type' => ['sometimes', Rule::enum(MoveType::class)],
            'source_type' => ['sometimes', Rule::enum(StockSource::class)],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
    }

    /**
     * @return array{branch_id?: string, location_id?: string, item_id?: string, move_type?: string, source_type?: string, from?: string, to?: string}
     */
    public function filters(): array
    {
        $filters = [];
        foreach (['branch_id', 'location_id', 'item_id'] as $key) {
            if ($this->filled($key)) {
                $filters[$key] = $this->string($key)->lower()->toString();
            }
        }
        foreach (['move_type', 'source_type', 'from', 'to'] as $key) {
            if ($this->filled($key)) {
                $filters[$key] = $this->string($key)->toString();
            }
        }

        return $filters;
    }
}

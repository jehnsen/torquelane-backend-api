<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class CollectWorkOrdersRequest extends ApiRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'work_order_ids' => ['required', 'array', 'min:1', 'max:50'],
            'work_order_ids.*' => ['string', 'ulid', 'distinct'],
        ];
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_values(array_map(fn (mixed $id): string => is_string($id) ? mb_strtolower($id) : '', $this->array('work_order_ids')));
    }
}

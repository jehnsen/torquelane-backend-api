<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class ListFleetPartsRequest extends PaginatedRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'customer_account_id' => ['sometimes', 'string', 'ulid'],
            'include_inactive' => ['sometimes', 'boolean'],
        ]);
    }

    /**
     * @return array{customer_account_id?: string, include_inactive?: bool}
     */
    public function filters(): array
    {
        $filters = [];
        if ($this->filled('customer_account_id')) {
            $filters['customer_account_id'] = $this->string('customer_account_id')->lower()->toString();
        }
        if ($this->has('include_inactive')) {
            $filters['include_inactive'] = $this->boolean('include_inactive');
        }

        return $filters;
    }
}

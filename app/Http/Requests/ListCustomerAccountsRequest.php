<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class ListCustomerAccountsRequest extends PaginatedRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'status' => ['sometimes', 'string', 'in:active,suspended'],
            'account_type' => ['sometimes', 'string', 'in:company,individual'],
            'q' => ['sometimes', 'string', 'max:100'],
        ]);
    }
}

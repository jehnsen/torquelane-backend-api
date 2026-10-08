<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class AutoScheduleRequest extends ApiRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'customer_account_id' => ['sometimes', 'string', 'ulid'],
            'branch_id' => ['sometimes', 'string', 'ulid'],
        ];
    }

    public function accountId(): ?string
    {
        return $this->filled('customer_account_id') ? $this->string('customer_account_id')->lower()->toString() : null;
    }

    public function branchId(): ?string
    {
        return $this->filled('branch_id') ? $this->string('branch_id')->lower()->toString() : null;
    }
}

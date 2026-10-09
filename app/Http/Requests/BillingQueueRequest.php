<?php

declare(strict_types=1);

namespace App\Http\Requests;

/** The billing queue, optionally for one account. */
final class BillingQueueRequest extends PaginatedRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'customer_account_id' => ['sometimes', 'string', 'ulid'],
        ]);
    }

    public function accountId(): ?string
    {
        return $this->filled('customer_account_id') ? $this->string('customer_account_id')->lower()->toString() : null;
    }
}

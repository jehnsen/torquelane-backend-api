<?php

declare(strict_types=1);

namespace App\Http\Requests;

/** Payments: posted or void, a method, an account, a branch, a search on number or reference. */
final class ListPaymentsRequest extends PaginatedRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'status' => ['sometimes', 'string', 'in:posted,void'],
            'method' => ['sometimes', 'string', 'in:cash,gcash,maya,card,bank_transfer,check'],
            'customer_account_id' => ['sometimes', 'string', 'ulid'],
            'branch_id' => ['sometimes', 'string', 'ulid'],
            'q' => ['sometimes', 'string', 'max:100'],
        ]);
    }

    /**
     * @return array{status?: string, method?: string, customer_account_id?: string, branch_id?: string, q?: string}
     */
    public function filters(): array
    {
        $filters = [];
        foreach (['customer_account_id', 'branch_id'] as $key) {
            if ($this->filled($key)) {
                $filters[$key] = $this->string($key)->lower()->toString();
            }
        }
        foreach (['status', 'method', 'q'] as $key) {
            if ($this->filled($key)) {
                $filters[$key] = trim($this->string($key)->toString());
            }
        }

        return $filters;
    }
}

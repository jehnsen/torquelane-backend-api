<?php

declare(strict_types=1);

namespace App\Http\Requests;

/** Invoices: a status (or `open` / `overdue`), an account, a branch, a search on number or buyer. */
final class ListInvoicesRequest extends PaginatedRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'status' => ['sometimes', 'string', 'in:draft,issued,partially_paid,paid,void,open,overdue'],
            'customer_account_id' => ['sometimes', 'string', 'ulid'],
            'branch_id' => ['sometimes', 'string', 'ulid'],
            'q' => ['sometimes', 'string', 'max:100'],
        ]);
    }

    /**
     * @return array{status?: string, customer_account_id?: string, branch_id?: string, q?: string}
     */
    public function filters(): array
    {
        $filters = [];
        foreach (['customer_account_id', 'branch_id'] as $key) {
            if ($this->filled($key)) {
                $filters[$key] = $this->string($key)->lower()->toString();
            }
        }
        foreach (['status', 'q'] as $key) {
            if ($this->filled($key)) {
                $filters[$key] = trim($this->string($key)->toString());
            }
        }

        return $filters;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests;

/** Credit notes, refunds and withholding certificates: standing or void, an account, a branch, an invoice. */
final class ListSettlementsRequest extends PaginatedRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'status' => ['sometimes', 'string', 'in:issued,posted,void'],
            'customer_account_id' => ['sometimes', 'string', 'ulid'],
            'branch_id' => ['sometimes', 'string', 'ulid'],
            'invoice_id' => ['sometimes', 'string', 'ulid'],
        ]);
    }

    /**
     * @param  list<string>  $columns  the filters the list being read has
     * @return array{status?: string, customer_account_id?: string, branch_id?: string, invoice_id?: string}
     */
    public function filters(array $columns = ['status', 'customer_account_id', 'branch_id', 'invoice_id']): array
    {
        $filters = [];
        foreach (['customer_account_id', 'branch_id', 'invoice_id'] as $key) {
            if (in_array($key, $columns, true) && $this->filled($key)) {
                $filters[$key] = $this->string($key)->lower()->toString();
            }
        }
        if ($this->filled('status')) {
            $filters['status'] = trim($this->string('status')->toString());
        }

        return $filters;
    }
}

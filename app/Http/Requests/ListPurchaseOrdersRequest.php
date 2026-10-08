<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\PurchaseOrders\PurchaseOrderStatus;
use Illuminate\Validation\Rule;

final class ListPurchaseOrdersRequest extends PaginatedRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'customer_account_id' => ['sometimes', 'string', 'ulid'],
            'status' => ['sometimes', 'string', Rule::enum(PurchaseOrderStatus::class)],
            'format' => ['sometimes', 'string', 'in:csv,xlsx'],
        ]);
    }

    /**
     * @return array{customer_account_id?: string, status?: string}
     */
    public function filters(): array
    {
        $filters = [];
        if ($this->filled('customer_account_id')) {
            $filters['customer_account_id'] = $this->string('customer_account_id')->lower()->toString();
        }
        if ($this->filled('status')) {
            $filters['status'] = $this->string('status')->toString();
        }

        return $filters;
    }

    /** Export format; xlsx by default, as ../web exported. */
    public function format(): string
    {
        return $this->string('format', 'xlsx')->toString();
    }
}

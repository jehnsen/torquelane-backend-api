<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Shop purchase orders, goods receipts, stock counts and transfers: the
 * branch they belong to, a status, and (where it makes sense) a vendor, a
 * purchase order, or a search term.
 */
final class ListStockDocumentsRequest extends PaginatedRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'branch_id' => ['sometimes', 'string', 'ulid'],
            'vendor_id' => ['sometimes', 'string', 'ulid'],
            'shop_purchase_order_id' => ['sometimes', 'string', 'ulid'],
            'status' => ['sometimes', 'string', 'in:draft,issued,partially_received,received,open,cancelled,posted,voided'],
            'q' => ['sometimes', 'string', 'max:100'],
        ]);
    }

    /**
     * @return array{branch_id?: string, vendor_id?: string, shop_purchase_order_id?: string, status?: string, q?: string}
     */
    public function filters(): array
    {
        $filters = [];
        foreach (['branch_id', 'vendor_id', 'shop_purchase_order_id'] as $key) {
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

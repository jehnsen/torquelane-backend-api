<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\WorkOrders\WorkOrderStatus;

final class ListWorkOrdersRequest extends PaginatedRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'status' => ['sometimes', 'array', 'max:9'],
            'status.*' => ['string', 'in:'.implode(',', array_map(fn (WorkOrderStatus $s): string => $s->value, WorkOrderStatus::cases()))],
            'customer_account_id' => ['sometimes', 'string', 'ulid'],
            'vehicle_id' => ['sometimes', 'string', 'ulid'],
            'branch_id' => ['sometimes', 'string', 'ulid'],
            'scheduled_for' => ['sometimes', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array{status?: list<string>, customer_account_id?: string, vehicle_id?: string, branch_id?: string, scheduled_for?: string}
     */
    public function filters(): array
    {
        $filters = [];
        if ($this->has('status')) {
            $filters['status'] = array_values(array_filter($this->array('status'), 'is_string'));
        }
        foreach (['customer_account_id', 'vehicle_id', 'branch_id'] as $key) {
            if ($this->filled($key)) {
                $filters[$key] = $this->string($key)->lower()->toString();
            }
        }
        if ($this->filled('scheduled_for')) {
            $filters['scheduled_for'] = $this->string('scheduled_for')->toString();
        }

        return $filters;
    }
}

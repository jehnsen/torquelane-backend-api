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
            // The list screens' buckets: active (neither closed nor cancelled), completed (closed), cancelled.
            'stage' => ['sometimes', 'string', 'in:active,completed,cancelled'],
            'type' => ['sometimes', 'string', 'in:'.implode(',', WorkOrderRules::TYPES)],
            // Reference, title, technician, vendor, plate or customer, case-insensitive.
            'q' => ['sometimes', 'string', 'max:100'],
            'technician_id' => ['sometimes', 'string', 'ulid'],
            'bay_id' => ['sometimes', 'string', 'ulid'],
            // opened (newest first, the default), scheduled (soonest first),
            // completed (latest first), queue (running first, then soonest).
            'sort' => ['sometimes', 'string', 'in:opened,scheduled,completed,queue'],
        ];
    }

    /**
     * @return array{status?: list<string>, customer_account_id?: string, vehicle_id?: string, branch_id?: string, scheduled_for?: string, technician_id?: string, bay_id?: string, stage?: string, type?: string, q?: string, sort?: string}
     */
    public function filters(): array
    {
        $filters = [];
        if ($this->has('status')) {
            $filters['status'] = array_values(array_filter($this->array('status'), 'is_string'));
        }
        foreach (['customer_account_id', 'vehicle_id', 'branch_id', 'technician_id', 'bay_id'] as $key) {
            if ($this->filled($key)) {
                $filters[$key] = $this->string($key)->lower()->toString();
            }
        }
        if ($this->filled('scheduled_for')) {
            $filters['scheduled_for'] = $this->string('scheduled_for')->toString();
        }
        foreach (['stage', 'type', 'q', 'sort'] as $key) {
            if ($this->filled($key)) {
                $filters[$key] = trim($this->string($key)->toString());
            }
        }

        return $filters;
    }
}

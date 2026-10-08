<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Fleet\PlateNumber;

final class ListVehiclesRequest extends PaginatedRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'status' => ['sometimes', 'string', 'in:active,in_service,down'],
            'customer_account_id' => ['sometimes', 'string', 'ulid'],
            // Plate or VIN, matched on the normalised form (check-in style).
            'q' => ['sometimes', 'string', 'max:64'],
            'include_archived' => ['sometimes', 'boolean'],
            // Derived, so evaluated per request: the worst PMS band, or an odometer reading over 14 days old.
            'pms' => ['sometimes', 'string', 'in:ok,due_soon,overdue,stale'],
            'department' => ['sometimes', 'string', 'max:100'],
            // Plate, make, model, driver or location, case-insensitive substring.
            'search' => ['sometimes', 'string', 'max:100'],
            // plate (the default) or health (least healthy first).
            'sort' => ['sometimes', 'string', 'in:plate,health'],
        ]);
    }

    /**
     * @return array{status?: string, customer_account_id?: string, q?: string, pms?: string, department?: string, search?: string, sort?: string}
     */
    public function filters(): array
    {
        $filters = [];
        if ($this->filled('status')) {
            $filters['status'] = $this->string('status')->toString();
        }
        if ($this->filled('customer_account_id')) {
            $filters['customer_account_id'] = $this->string('customer_account_id')->lower()->toString();
        }
        if ($this->filled('q')) {
            $filters['q'] = PlateNumber::normalise($this->string('q')->toString());
        }
        foreach (['pms', 'department', 'search', 'sort'] as $key) {
            if ($this->filled($key)) {
                $filters[$key] = trim($this->string($key)->toString());
            }
        }

        return $filters;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Shared\Calendar;
use Carbon\CarbonImmutable;

/**
 * Vendor bills and payments to vendors: a status (for bills also `open` and
 * `overdue`), a method, a vendor, a branch, a search; and for aging, `as_of`.
 */
final class ListPayablesRequest extends PaginatedRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'status' => ['sometimes', 'string', 'in:posted,partially_paid,paid,void,open,overdue'],
            'method' => ['sometimes', 'string', 'in:cash,gcash,maya,card,bank_transfer,check'],
            'vendor_id' => ['sometimes', 'string', 'ulid'],
            'branch_id' => ['sometimes', 'string', 'ulid'],
            'q' => ['sometimes', 'string', 'max:100'],
            'as_of' => ['sometimes', 'date_format:Y-m-d'],
        ]);
    }

    /**
     * @return array{status?: string, method?: string, vendor_id?: string, branch_id?: string, q?: string}
     */
    public function filters(): array
    {
        $filters = [];
        foreach (['vendor_id', 'branch_id'] as $key) {
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

    public function vendorId(): ?string
    {
        return $this->filled('vendor_id') ? $this->string('vendor_id')->lower()->toString() : null;
    }

    public function asOf(): string
    {
        return $this->filled('as_of') ? Input::string($this->input('as_of')) : Calendar::toDate(CarbonImmutable::now());
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Documents\DocumentKind;
use Illuminate\Validation\Rule;

final class ListDocumentsRequest extends PaginatedRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'vehicle_id' => ['sometimes', 'string', 'ulid'],
            'work_order_id' => ['sometimes', 'string', 'ulid'],
            'customer_account_id' => ['sometimes', 'string', 'ulid'],
            'kind' => ['sometimes', 'string', Rule::enum(DocumentKind::class)],
            // Expiring within N days (already-expired included).
            'expiring_within' => ['sometimes', 'integer', 'min:0', 'max:3650'],
            // The expiry badge: expired, expiring (within 30 days) or ok.
            'status' => ['sometimes', 'string', 'in:expired,expiring,ok'],
            // Name, notes, uploader or plate, case-insensitive.
            'q' => ['sometimes', 'string', 'max:100'],
            // uploaded (newest first, the default) or expiry (soonest first, undated last).
            'sort' => ['sometimes', 'string', 'in:uploaded,expiry'],
        ]);
    }

    /**
     * @return array{vehicle_id?: string, work_order_id?: string, customer_account_id?: string, kind?: string, expiring_within?: int, status?: string, q?: string, sort?: string}
     */
    public function filters(): array
    {
        $filters = [];
        foreach (['vehicle_id', 'work_order_id', 'customer_account_id'] as $id) {
            if ($this->filled($id)) {
                $filters[$id] = $this->string($id)->lower()->toString();
            }
        }
        if ($this->filled('kind')) {
            $filters['kind'] = $this->string('kind')->toString();
        }
        if ($this->filled('expiring_within')) {
            $filters['expiring_within'] = $this->integer('expiring_within');
        }
        foreach (['status', 'q', 'sort'] as $key) {
            if ($this->filled($key)) {
                $filters[$key] = trim($this->string($key)->toString());
            }
        }

        return $filters;
    }
}

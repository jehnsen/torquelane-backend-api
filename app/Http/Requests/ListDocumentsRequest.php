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
            'customer_account_id' => ['sometimes', 'string', 'ulid'],
            'kind' => ['sometimes', 'string', Rule::enum(DocumentKind::class)],
            // Expiring within N days (already-expired included).
            'expiring_within' => ['sometimes', 'integer', 'min:0', 'max:3650'],
        ]);
    }

    /**
     * @return array{vehicle_id?: string, customer_account_id?: string, kind?: string, expiring_within?: int}
     */
    public function filters(): array
    {
        $filters = [];
        foreach (['vehicle_id', 'customer_account_id'] as $id) {
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

        return $filters;
    }
}

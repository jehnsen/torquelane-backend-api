<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ReorderRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['sometimes', 'string', 'ulid'],
            'horizon_weeks' => ['sometimes', 'integer', 'min:1', 'max:26'],
            'customer_account_id' => ['sometimes', 'string', 'ulid'],
            'all' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array{branch_id?: string, horizon_weeks?: int, customer_account_id?: string, all?: bool}
     */
    public function filters(): array
    {
        $filters = [];
        foreach (['branch_id', 'customer_account_id'] as $key) {
            if ($this->filled($key)) {
                $filters[$key] = $this->string($key)->lower()->toString();
            }
        }
        if ($this->filled('horizon_weeks')) {
            $filters['horizon_weeks'] = $this->integer('horizon_weeks');
        }
        if ($this->has('all')) {
            $filters['all'] = $this->boolean('all');
        }

        return $filters;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

final class SaveExportMappingsRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'mappings' => ['required', 'array', 'min:1', 'max:200'],
            'mappings.*.account_id' => ['required', 'string', 'ulid'],
            'mappings.*.target' => ['required', 'string', Rule::in(['xero', 'quickbooks'])],
            'mappings.*.external_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'mappings.*.external_name' => ['sometimes', 'nullable', 'string', 'max:160'],
        ];
    }

    /**
     * @return list<array{account_id: string, target: string, external_code?: string|null, external_name?: string|null}>
     */
    public function mappings(): array
    {
        $rows = [];
        foreach (Input::rows($this->validated('mappings')) as $row) {
            $rows[] = [
                'account_id' => strtolower(Input::string($row['account_id'] ?? null)),
                'target' => Input::string($row['target'] ?? null),
                'external_code' => isset($row['external_code']) ? Input::string($row['external_code']) : null,
                'external_name' => isset($row['external_name']) ? Input::string($row['external_name']) : null,
            ];
        }

        return $rows;
    }
}

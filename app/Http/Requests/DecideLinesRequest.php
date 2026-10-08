<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

final class DecideLinesRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decisions' => ['required', 'array', 'min:1', 'max:100'],
            'decisions.*.line_id' => ['required', 'string', 'ulid', 'distinct'],
            'decisions.*.decision' => ['required', 'string', Rule::in(WorkOrderRules::decisions())],
            // Required to decline safety-critical work (checked against the line).
            'decisions.*.note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return list<array{line_id: string, decision: string, note?: string|null}>
     */
    public function decisions(): array
    {
        $out = [];
        foreach ($this->array('decisions') as $decision) {
            if (! is_array($decision)) {
                continue;
            }
            $out[] = [
                'line_id' => mb_strtolower(is_string($decision['line_id'] ?? null) ? $decision['line_id'] : ''),
                'decision' => is_string($decision['decision'] ?? null) ? $decision['decision'] : '',
                'note' => is_string($decision['note'] ?? null) ? $decision['note'] : null,
            ];
        }

        return $out;
    }
}

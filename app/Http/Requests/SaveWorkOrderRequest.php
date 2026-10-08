<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * POST /work-orders (createDraft) and PATCH /work-orders/{id} (updateDraft).
 * Lines are set on creation here, afterwards through PUT …/lines.
 */
final class SaveWorkOrderRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');

        $rules = WorkOrderRules::header($this->presence()) + [
            'vehicle_id' => $creating ? ['required', 'string', 'ulid'] : ['prohibited'],
            'branch_id' => $creating ? ['sometimes', 'nullable', 'string', 'ulid'] : ['prohibited'],
            'status' => ['prohibited'],
            'reference' => ['prohibited'],
        ];

        if ($creating) {
            $rules += ['lines' => ['sometimes', 'array', 'max:100']] + WorkOrderRules::lines();
            $rules['lines.*.id'] = ['prohibited'];
        } else {
            $rules['lines'] = ['prohibited'];
        }

        return $rules;
    }

    /**
     * The opening lines (creation only), cleaned to their input keys.
     *
     * @return list<array<string, mixed>>
     */
    public function lines(): array
    {
        $validated = $this->validated();

        return WorkOrderLinesRequest::normalise(is_array($validated['lines'] ?? null) ? $validated['lines'] : []);
    }

    /**
     * The header fields (lines apart, see lines()).
     *
     * @return array<string, mixed>
     */
    public function workOrderData(): array
    {
        $data = $this->validated();
        foreach (['vehicle_id', 'branch_id'] as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $data[$key] = mb_strtolower($data[$key]);
            }
        }
        if (isset($data['task_ids']) && is_array($data['task_ids'])) {
            $data['task_ids'] = array_values(array_map(mb_strtolower(...), array_filter($data['task_ids'], 'is_string')));
        }
        unset($data['lines']);

        return $data;
    }
}

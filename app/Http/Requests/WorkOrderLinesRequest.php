<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * PUT /work-orders/{id}/lines (recordLines): the draft's full line list.
 */
final class WorkOrderLinesRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['lines' => ['present', 'array', 'max:100']] + WorkOrderRules::lines();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function lines(): array
    {
        $validated = $this->validated();

        return self::normalise(is_array($validated['lines'] ?? null) ? $validated['lines'] : []);
    }

    /**
     * Only the validated input keys survive; ids lower-cased (ULIDs are stored lower-case).
     *
     * @param  array<array-key, mixed>  $lines
     * @return list<array<string, mixed>>
     */
    public static function normalise(array $lines): array
    {
        $keys = ['id', 'service_task_id', 'description', 'category', 'quantity', 'unit_part_rate_cents', 'labour_hours', 'labour_rate_cents', 'urgency', 'parts_source', 'item_id', 'photos'];
        $out = [];
        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }
            $clean = array_intersect_key($line, array_flip($keys));
            foreach (['id', 'service_task_id', 'item_id'] as $id) {
                if (isset($clean[$id]) && is_string($clean[$id])) {
                    $clean[$id] = mb_strtolower($clean[$id]);
                }
            }
            $out[] = $clean;
        }

        return $out;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * The bodies of the lifecycle steps: schedule, start, complete, close,
 * cancel. Each route uses the rules for its own step (route name suffix).
 */
final class WorkOrderStepRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return match ($this->step()) {
            'schedule' => [
                'scheduled_for' => ['required', 'date_format:Y-m-d'],
                'scheduled_time' => ['required', 'string', 'regex:'.WorkOrderRules::TIME],
                'bay_id' => ['required', 'string', 'ulid'],
                'technician_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            ],
            'start' => [
                'technician_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            ],
            'complete' => [
                'findings' => ['sometimes', 'string', 'max:10000'],
                'odometer_at_service' => ['sometimes', 'numeric', 'decimal:0,3', 'min:0', 'max:99999999'],
                'parts' => ['sometimes', 'array', 'max:100'],
                'parts.*.part_number' => ['sometimes', 'nullable', 'string', 'max:64'],
                'parts.*.name' => ['required', 'string', 'max:255'],
                'parts.*.quantity' => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:99999'],
                'parts.*.unit_cost_cents' => ['required', 'integer', 'min:0', 'max:100000000000'],
                'task_ids' => ['sometimes', 'array', 'max:50'],
                'task_ids.*' => ['string', 'ulid', 'distinct'],
            ],
            'close' => [
                // Re-approves a cost variance past the threshold (needs approval authority).
                'variance_approved' => ['sometimes', 'boolean'],
            ],
            'cancel' => [
                'reason' => ['required', 'string', 'max:2000'],
            ],
            default => [],
        };
    }

    public function technicianId(): ?string
    {
        return $this->filled('technician_id') ? $this->string('technician_id')->lower()->toString() : null;
    }

    /**
     * @return array{findings?: string, odometer_at_service?: string, parts?: list<array<string, mixed>>, task_ids?: list<string>}
     */
    public function completion(): array
    {
        $data = $this->validated();
        $out = [];
        if (isset($data['findings']) && is_string($data['findings'])) {
            $out['findings'] = $data['findings'];
        }
        if (isset($data['odometer_at_service']) && is_scalar($data['odometer_at_service'])) {
            $out['odometer_at_service'] = (string) $data['odometer_at_service'];
        }
        if (isset($data['parts']) && is_array($data['parts'])) {
            $out['parts'] = [];
            foreach ($data['parts'] as $part) {
                if (is_array($part)) {
                    $clean = [];
                    foreach ($part as $key => $value) {
                        $clean[(string) $key] = $value;
                    }
                    $out['parts'][] = $clean;
                }
            }
        }
        if (isset($data['task_ids']) && is_array($data['task_ids'])) {
            $out['task_ids'] = array_values(array_map(mb_strtolower(...), array_filter($data['task_ids'], 'is_string')));
        }

        return $out;
    }

    private function step(): string
    {
        $name = (string) $this->route()?->getName();

        return substr($name, (int) strrpos($name, '.') + 1);
    }
}

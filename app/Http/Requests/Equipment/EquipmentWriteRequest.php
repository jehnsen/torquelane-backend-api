<?php

declare(strict_types=1);

namespace App\Http\Requests\Equipment;

use App\Domain\Equipment\EquipmentVocabulary;
use App\Http\Requests\ApiRequest;
use App\Http\Requests\Input;
use Illuminate\Validation\Rule;

/**
 * The small writes against one tool or one package: a status change, a usage
 * entry, a service entry, a package's equipment profile. Each route reads the
 * part it needs.
 */
final class EquipmentWriteRequest extends ApiRequest
{
    private const string DECIMAL = 'regex:/^\d+(\.\d{1,3})?$/';

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'status' => ['sometimes', 'string', Rule::in(array_keys(EquipmentVocabulary::STATUSES))],
            // A usage entry.
            'hours' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000', self::DECIMAL],
            'cycles' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'used_at' => ['sometimes', 'nullable', 'date'],
            'detailing_booking_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            // A service entry.
            'kind' => ['sometimes', 'string', Rule::in(array_keys(EquipmentVocabulary::MAINTENANCE_KINDS))],
            'performed_at' => ['sometimes', 'nullable', 'date'],
            'performed_by' => ['sometimes', 'nullable', 'string', 'max:255'],
            'cost_cents' => ['sometimes', 'integer', 'min:0', 'max:100000000000'],
            'downtime_hours' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000', self::DECIMAL],
            'parts' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'resets_service' => ['sometimes', 'boolean'],
            // Parts the service took from the branch's store.
            'issued_parts' => ['sometimes', 'array', 'max:50'],
            'issued_parts.*.item_id' => ['required', 'string', 'ulid'],
            'issued_parts.*.quantity' => ['required', 'numeric', 'gt:0', 'max:1000000', self::DECIMAL],
            // A package's equipment profile: the whole list.
            'lines' => ['sometimes', 'array', 'max:100'],
            'lines.*.equipment_id' => ['required', 'string', 'ulid'],
            'lines.*.hours_per_job' => ['required', 'numeric', 'min:0', 'max:1000', self::DECIMAL],
        ];
    }

    public function branchId(): ?string
    {
        return $this->filled('branch_id') ? Input::id($this->validated('branch_id')) : null;
    }

    public function status(): ?string
    {
        return $this->filled('status') ? Input::string($this->validated('status')) : null;
    }

    /**
     * @return array{hours: string|null, cycles: int|null, used_at: string|null, detailing_booking_id: string|null, note: string|null}
     */
    public function usage(): array
    {
        return [
            'hours' => $this->filled('hours') ? Input::string($this->validated('hours')) : null,
            'cycles' => $this->filled('cycles') ? Input::int($this->validated('cycles')) : null,
            'used_at' => Input::text($this->validated('used_at')),
            'detailing_booking_id' => $this->filled('detailing_booking_id') ? Input::id($this->validated('detailing_booking_id')) : null,
            'note' => Input::text($this->validated('note')),
        ];
    }

    /**
     * @return array{kind: string, performed_at: string|null, performed_by: string|null, cost_cents: int, downtime_hours: string|null, parts: string|null, notes: string|null, resets_service: bool, issued_parts: list<array{item_id: string, quantity: string}>}
     */
    public function service(): array
    {
        return [
            'kind' => $this->filled('kind') ? Input::string($this->validated('kind')) : 'preventive',
            'performed_at' => Input::text($this->validated('performed_at')),
            'performed_by' => Input::text($this->validated('performed_by')),
            'cost_cents' => $this->filled('cost_cents') ? Input::int($this->validated('cost_cents')) : 0,
            'downtime_hours' => $this->filled('downtime_hours') ? Input::string($this->validated('downtime_hours')) : null,
            'parts' => Input::text($this->validated('parts')),
            'notes' => Input::text($this->validated('notes')),
            'resets_service' => ! $this->has('resets_service') || $this->boolean('resets_service'),
            'issued_parts' => array_map(fn (array $row): array => [
                'item_id' => Input::id($row['item_id'] ?? ''),
                'quantity' => Input::string($row['quantity'] ?? '0'),
            ], Input::rows($this->validated('issued_parts'))),
        ];
    }

    /**
     * @return list<array{equipment_id: string, hours_per_job: string}>
     */
    public function lines(): array
    {
        return array_map(fn (array $row): array => [
            'equipment_id' => Input::id($row['equipment_id'] ?? ''),
            'hours_per_job' => Input::string($row['hours_per_job'] ?? '0'),
        ], Input::rows($this->validated('lines')));
    }
}

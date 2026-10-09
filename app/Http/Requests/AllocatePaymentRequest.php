<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Applying a payment's credit: the invoices and amounts, or nothing (oldest due first). */
final class AllocatePaymentRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return self::allocationRules();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function allocationRules(): array
    {
        return [
            'allocations' => ['sometimes', 'nullable', 'array', 'max:100'],
            'allocations.*.invoice_id' => ['required', 'string', 'ulid'],
            'allocations.*.amount_cents' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return list<array{invoice_id: string, amount_cents: int}>|null null = oldest due first
     */
    public function allocations(): ?array
    {
        return self::read($this);
    }

    /**
     * @return list<array{invoice_id: string, amount_cents: int}>|null
     */
    public static function read(FormRequest $request): ?array
    {
        $rows = $request->validated('allocations');
        if (! is_array($rows)) {
            return null;
        }

        return array_map(fn (array $row): array => [
            'invoice_id' => Input::id($row['invoice_id'] ?? ''),
            'amount_cents' => Input::int($row['amount_cents'] ?? 0),
        ], Input::rows($rows));
    }
}

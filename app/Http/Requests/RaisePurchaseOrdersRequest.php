<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * Purchase requests from the forecast: the parts chosen, over the horizon the
 * forecast was read at. Quantities and prices come from the server's own
 * forecast, never from here.
 */
final class RaisePurchaseOrdersRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'customer_account_id' => [Rule::requiredIf(fn (): bool => $this->tenant()?->isStaff() ?? false), 'string', 'ulid'],
            'horizon_weeks' => ['sometimes', 'integer', 'min:1', 'max:52'],
            'part_ids' => ['required', 'array', 'min:1', 'max:200'],
            'part_ids.*' => ['string', 'ulid', 'distinct'],
            'notes' => ['sometimes', 'string', 'max:2000'],
        ];
    }

    public function accountId(): ?string
    {
        return $this->filled('customer_account_id') ? $this->string('customer_account_id')->lower()->toString() : null;
    }

    public function horizonWeeks(): int
    {
        return $this->integer('horizon_weeks', DemandForecastRequest::DEFAULT_HORIZON_WEEKS);
    }

    /**
     * @return list<string>
     */
    public function partIds(): array
    {
        return array_values(array_map(fn (mixed $id): string => is_string($id) ? mb_strtolower($id) : '', $this->array('part_ids')));
    }

    public function notes(): string
    {
        return trim($this->string('notes')->toString());
    }
}

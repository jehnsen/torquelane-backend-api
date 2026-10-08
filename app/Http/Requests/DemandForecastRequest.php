<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * The forecast is per customer account (stock is): staff name it, a portal
 * user's is their own. `horizon_weeks` defaults to 6, as ../web's page.
 */
final class DemandForecastRequest extends ApiRequest
{
    public const int DEFAULT_HORIZON_WEEKS = 6;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'customer_account_id' => [Rule::requiredIf(fn (): bool => $this->tenant()?->isStaff() ?? false), 'string', 'ulid'],
            'horizon_weeks' => ['sometimes', 'integer', 'min:1', 'max:52'],
        ];
    }

    public function accountId(): ?string
    {
        return $this->filled('customer_account_id') ? $this->string('customer_account_id')->lower()->toString() : null;
    }

    public function horizonWeeks(): int
    {
        return $this->integer('horizon_weeks', self::DEFAULT_HORIZON_WEEKS);
    }
}

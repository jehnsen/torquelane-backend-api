<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Shared\Calendar;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Validation\Validator;

/**
 * Receivables reads over business dates (Asia/Manila): aging `as_of` (default
 * today); a statement or revenue over `from`..`to` (default the last 30 days
 * to today).
 */
final class ReceivablesRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'as_of' => ['sometimes', 'date_format:Y-m-d'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'customer_account_id' => ['sometimes', 'string', 'ulid'],
        ];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isEmpty() && $this->from() > $this->to()) {
                    $validator->errors()->add('to', 'The range ends before it starts.');
                }
            },
        ];
    }

    public function asOf(): string
    {
        return $this->filled('as_of') ? Input::string($this->validated('as_of')) : Calendar::toDate(CarbonImmutable::now());
    }

    public function to(): string
    {
        return $this->filled('to') ? Input::string($this->input('to')) : Calendar::toDate(CarbonImmutable::now());
    }

    public function from(): string
    {
        return $this->filled('from') ? Input::string($this->input('from')) : Calendar::toDate(Calendar::addDays(Calendar::parseDate($this->to()), -30));
    }

    public function accountId(): ?string
    {
        return $this->filled('customer_account_id') ? Input::id($this->validated('customer_account_id')) : null;
    }
}

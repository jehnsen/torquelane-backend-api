<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Shared\Calendar;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Validation\Validator;

/**
 * Accounting reads over business dates (Asia/Manila): `as_of` for a balance
 * (default today), `from`..`to` for a range (default: the first of this month
 * to today).
 */
class LedgerRangeRequest extends PaginatedRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'as_of' => ['sometimes', 'date_format:Y-m-d'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
        ]);
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
        return $this->filled('as_of') ? Input::string($this->input('as_of')) : Calendar::toDate(CarbonImmutable::now());
    }

    public function to(): string
    {
        return $this->filled('to') ? Input::string($this->input('to')) : Calendar::toDate(CarbonImmutable::now());
    }

    public function from(): string
    {
        return $this->filled('from') ? Input::string($this->input('from')) : substr($this->to(), 0, 8).'01';
    }
}

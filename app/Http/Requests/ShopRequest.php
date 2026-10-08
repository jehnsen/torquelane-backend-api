<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Carbon\CarbonImmutable;

/**
 * Shop views: a day (default today), or a [from, to) period (default this
 * month to date), optionally narrowed to one branch. Dates are Manila
 * calendar dates.
 */
final class ShopRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after:from'],
            'days' => ['sometimes', 'integer', 'min:1', 'max:90'],
            'branch_id' => ['sometimes', 'string', 'ulid'],
            'months' => ['sometimes', 'integer', 'in:3,6,12'],
        ];
    }

    public function day(): CarbonImmutable
    {
        return $this->filled('date')
            ? CarbonImmutable::parse($this->string('date')->toString(), 'Asia/Manila')->startOfDay()
            : CarbonImmutable::now('Asia/Manila')->startOfDay();
    }

    public function from(): CarbonImmutable
    {
        return $this->filled('from')
            ? CarbonImmutable::parse($this->string('from')->toString(), 'Asia/Manila')->startOfDay()
            : CarbonImmutable::now('Asia/Manila')->startOfMonth();
    }

    /** Exclusive: the start of the day after the last one counted. */
    public function to(): CarbonImmutable
    {
        return $this->filled('to')
            ? CarbonImmutable::parse($this->string('to')->toString(), 'Asia/Manila')->startOfDay()
            : CarbonImmutable::now('Asia/Manila')->addDay()->startOfDay();
    }

    public function branchId(): ?string
    {
        return $this->filled('branch_id') ? $this->string('branch_id')->lower()->toString() : null;
    }
}

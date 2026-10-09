<?php

declare(strict_types=1);

namespace App\Http\Requests;

/** Cancelling a purchase order, voiding a goods receipt: the reason is required and kept. */
final class StockReasonRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:3', 'max:500']];
    }

    public function reason(): string
    {
        return trim($this->string('reason')->toString());
    }
}

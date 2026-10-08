<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class CancelPurchaseOrderRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:2000']];
    }

    public function reason(): string
    {
        return trim($this->string('reason')->toString());
    }
}

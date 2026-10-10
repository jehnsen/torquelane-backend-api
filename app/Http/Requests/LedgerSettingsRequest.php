<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

final class LedgerSettingsRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['accounting_target' => ['required', 'string', Rule::in(['none', 'xero', 'quickbooks'])]];
    }

    public function target(): string
    {
        return Input::string($this->input('accounting_target'));
    }
}

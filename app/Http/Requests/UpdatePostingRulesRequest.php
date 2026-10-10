<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Ledger\RuleKey;
use Illuminate\Validation\Rule;

final class UpdatePostingRulesRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'rules' => ['required', 'array', 'min:1', 'max:50'],
            'rules.*.key' => ['required', 'string', 'distinct', Rule::in(array_map(fn (RuleKey $k): string => $k->value, RuleKey::cases()))],
            'rules.*.account_id' => ['required', 'string', 'ulid'],
        ];
    }

    /**
     * @return list<array{key: string, account_id: string}>
     */
    public function changes(): array
    {
        $changes = [];
        foreach (Input::rows($this->validated('rules')) as $row) {
            $changes[] = ['key' => Input::string($row['key'] ?? null), 'account_id' => strtolower(Input::string($row['account_id'] ?? null))];
        }

        return $changes;
    }
}

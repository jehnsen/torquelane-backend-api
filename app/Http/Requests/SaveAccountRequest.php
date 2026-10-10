<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Ledger\AccountType;
use App\Domain\Ledger\Side;
use Illuminate\Validation\Rule;

final class SaveAccountRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $presence = $this->presence();

        return [
            'code' => [$presence, 'string', 'regex:/^[A-Za-z0-9.\-]{1,16}$/'],
            'name' => [$presence, 'string', 'min:2', 'max:120'],
            'type' => [$presence, 'string', Rule::in(array_map(fn (AccountType $t): string => $t->value, AccountType::cases()))],
            'normal_side' => ['sometimes', 'string', Rule::in(array_map(fn (Side $s): string => $s->value, Side::cases()))],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array{code: string, name: string, type: string, normal_side: string|null, description: string|null}
     */
    public function newAccount(): array
    {
        return [
            'code' => trim(Input::string($this->input('code'))),
            'name' => trim(Input::string($this->input('name'))),
            'type' => Input::string($this->input('type')),
            'normal_side' => $this->filled('normal_side') ? Input::string($this->input('normal_side')) : null,
            'description' => $this->has('description') ? Input::text($this->input('description')) : null,
        ];
    }

    /**
     * Only what the request sent.
     *
     * @return array{code?: string, name?: string, type?: string, normal_side?: string, description?: string|null, is_active?: bool}
     */
    public function changes(): array
    {
        $changes = [];
        foreach (['code', 'name'] as $key) {
            if ($this->has($key)) {
                $changes[$key] = trim(Input::string($this->input($key)));
            }
        }
        foreach (['type', 'normal_side'] as $key) {
            if ($this->has($key)) {
                $changes[$key] = Input::string($this->input($key));
            }
        }
        if ($this->has('description')) {
            $changes['description'] = Input::text($this->input('description'));
        }
        if ($this->has('is_active')) {
            $changes['is_active'] = $this->boolean('is_active');
        }

        return $changes;
    }
}

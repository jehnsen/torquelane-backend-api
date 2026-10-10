<?php

declare(strict_types=1);

namespace App\Http\Requests\Detailing;

use App\Http\Requests\ApiRequest;
use App\Http\Requests\Input;

final class SaveCategoryRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255'],
            'color' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^[a-z0-9_-]+$/'],
            'position' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000'],
        ];
    }

    public function branchId(): ?string
    {
        return $this->filled('branch_id') ? Input::id($this->validated('branch_id')) : null;
    }

    /**
     * @return array{name: string, slug: string|null, color: string|null, position: int|null}
     */
    public function category(): array
    {
        return [
            'name' => Input::string($this->validated('name')),
            'slug' => Input::text($this->validated('slug')),
            'color' => Input::text($this->validated('color')),
            'position' => $this->filled('position') ? Input::int($this->validated('position')) : null,
        ];
    }
}

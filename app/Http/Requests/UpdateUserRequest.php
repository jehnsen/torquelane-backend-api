<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Access\Role;
use Illuminate\Validation\Rule;

final class UpdateUserRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'role' => ['sometimes', 'string', Rule::enum(Role::class)],
            'status' => ['sometimes', 'string', 'in:active,disabled'],
            // [] = every branch (only an unrestricted granter may give that).
            'branch_ids' => ['sometimes', 'array', 'max:100'],
            'branch_ids.*' => ['string', 'ulid'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function userAttributes(): array
    {
        return array_diff_key($this->validated(), ['branch_ids' => true]);
    }

    /**
     * @return list<string>|null null when not being changed
     */
    public function branchIds(): ?array
    {
        return $this->has('branch_ids') ? self::ids($this->array('branch_ids')) : null;
    }

    /**
     * @param  array<mixed>  $values
     * @return list<string>
     */
    public static function ids(array $values): array
    {
        return array_values(array_map(fn (mixed $id): string => is_string($id) ? mb_strtolower($id) : '', $values));
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Access\Role;
use Illuminate\Validation\Rule;

final class StoreInvitationRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', 'string', Rule::enum(Role::class)],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Required for portal roles, forbidden for staff ones (decided by the role, in CreateInvitation).
            'customer_account_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'branch_ids' => ['sometimes', 'array', 'max:100'],
            'branch_ids.*' => ['string', 'ulid'],
        ];
    }

    /**
     * @return array{email: string, name: string, role: string, title: string|null, customer_account_id: string|null, branch_ids: list<string>}
     */
    public function invitation(): array
    {
        return [
            'email' => $this->string('email')->toString(),
            'name' => $this->string('name')->toString(),
            'role' => $this->string('role')->toString(),
            'title' => $this->filled('title') ? $this->string('title')->toString() : null,
            'customer_account_id' => $this->filled('customer_account_id') ? $this->string('customer_account_id')->lower()->toString() : null,
            'branch_ids' => UpdateUserRequest::ids($this->array('branch_ids')),
        ];
    }
}

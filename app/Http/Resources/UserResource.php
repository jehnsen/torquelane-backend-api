<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `branch_ids` is the staff member's pins; an empty list means every branch.
 *
 * @property User $resource
 */
final class UserResource extends JsonResource
{
    public function __construct(User $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->resource;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'username' => $user->username,
            'email' => $user->email,
            'title' => $user->title,
            'side' => $user->side->value,
            'role' => $user->role->value,
            'role_label' => $user->role->label(),
            'customer_account_id' => $user->customer_account_id,
            'branch_ids' => $user->relationLoaded('branches')
                ? $user->branches->map(fn (Branch $branch): string => $branch->id)->sort()->values()->all()
                : [],
            'status' => $user->status,
            'last_login_at' => $user->last_login_at?->toIso8601ZuluString(),
            'created_at' => $user->created_at->toIso8601ZuluString(),
        ];
    }
}

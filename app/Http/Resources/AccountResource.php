<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Account $resource
 */
final class AccountResource extends JsonResource
{
    public function __construct(Account $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $account = $this->resource;

        return [
            'id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'type' => $account->type->value,
            'normal_side' => $account->normal_side->value,
            'is_active' => $account->is_active,
            'is_system' => $account->is_system,
            'description' => $account->description,
        ];
    }
}

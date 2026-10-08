<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Invitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The token is never rendered; it exists only in the invitation email.
 *
 * @property Invitation $resource
 */
final class InvitationResource extends JsonResource
{
    public function __construct(Invitation $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $invitation = $this->resource;

        return [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'name' => $invitation->name,
            'side' => $invitation->side->value,
            'role' => $invitation->role->value,
            'title' => $invitation->title,
            'customer_account_id' => $invitation->customer_account_id,
            'branch_ids' => $invitation->branch_ids,
            'invited_by' => $invitation->invited_by,
            'status' => match (true) {
                $invitation->accepted_at !== null => 'accepted',
                $invitation->revoked_at !== null => 'revoked',
                $invitation->expires_at->isPast() => 'expired',
                default => 'pending',
            },
            'expires_at' => $invitation->expires_at->toIso8601ZuluString(),
            'accepted_at' => $invitation->accepted_at?->toIso8601ZuluString(),
            'revoked_at' => $invitation->revoked_at?->toIso8601ZuluString(),
            'created_at' => $invitation->created_at->toIso8601ZuluString(),
        ];
    }
}

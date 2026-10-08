<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Domain\Access\Side;

/**
 * The stored facts about an authenticated user that scope is resolved from.
 * Always read server-side from the users table, never from the request.
 */
final readonly class SessionFacts
{
    public function __construct(
        public string $userId,
        public ?string $organizationId,
        public Side $side,
        /** Raw, so an unrecognised stored value fails closed rather than throwing. */
        public ?string $role,
        public ?string $customerAccountId,
        public bool $disabled = false,
    ) {}
}

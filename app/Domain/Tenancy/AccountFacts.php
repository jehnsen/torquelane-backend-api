<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

final readonly class AccountFacts
{
    public function __construct(
        public string $id,
        public string $organizationId,
        public bool $suspended = false,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

final readonly class OrganizationFacts
{
    public function __construct(
        public string $id,
        public bool $suspended = false,
    ) {}
}

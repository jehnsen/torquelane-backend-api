<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

/**
 * Either a scope or the reason there is none, never both. Port of the
 * `{ scope, denial }` pair `explainTenantScope` returns.
 */
final readonly class ScopeResolution
{
    private function __construct(
        public ?TenantScope $scope,
        public ?ScopeDenial $denial,
    ) {}

    public static function granted(TenantScope $scope): self
    {
        return new self($scope, null);
    }

    public static function denied(ScopeDenial $denial): self
    {
        return new self(null, $denial);
    }
}

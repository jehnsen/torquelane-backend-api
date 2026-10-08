<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Domain\Access\Side;

/**
 * What one session may read: the whole organization (staff) or exactly one
 * customer account beneath it (portal). Port of the frontend's `TenantScope`
 * (`provider` → staff, `client` → portal).
 */
final readonly class TenantScope
{
    private function __construct(
        public Side $side,
        public string $organizationId,
        public ?string $customerAccountId,
    ) {}

    public static function staff(string $organizationId): self
    {
        return new self(Side::Staff, $organizationId, null);
    }

    public static function portal(string $organizationId, string $customerAccountId): self
    {
        return new self(Side::Portal, $organizationId, $customerAccountId);
    }

    public function isStaff(): bool
    {
        return $this->side === Side::Staff;
    }

    /**
     * Stable key for one scope's own bookkeeping (alert read/dismiss buckets
     * from a later phase). Organization-wide and account-level views are
     * deliberately distinct: staff dismissing an alert decide nothing on a
     * customer's behalf. Port of `tenantScopeKey`.
     */
    public function key(): string
    {
        return $this->customerAccountId === null
            ? 'organization:'.$this->organizationId
            : 'account:'.$this->customerAccountId;
    }
}

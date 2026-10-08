<?php

declare(strict_types=1);

namespace App\Tenancy;

use LogicException;

/**
 * A programming error, not a user error: a tenant model was read without a
 * tenant context, or written into another organization. Renders as a generic
 * 500 (never an envelope that hints at what exists).
 */
final class TenancyViolation extends LogicException
{
    public static function missingContext(string $what): self
    {
        return new self("No tenant context for {$what}. Resolve one (tenant middleware) or run inside TenantManager::system() with a named reason.");
    }

    public static function crossTenantWrite(string $model): self
    {
        return new self("Refusing to write {$model} into an organization other than the current tenant's.");
    }
}

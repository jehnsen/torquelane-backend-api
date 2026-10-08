<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Holds the current request's tenant context (scoped: one per request or job).
 *
 * Every tenant model's global scope reads it. With no context, a tenant query
 * throws (TenancyViolation) rather than returning everything or nothing: an
 * unscoped read outside a named system context is a bug to find, not a
 * situation to paper over.
 *
 * The only way around the scope is `system($reason, ...)`: seeders,
 * authentication (finding a user by email before anyone is known), tenant
 * resolution itself, invitation acceptance, and queue jobs that set their own
 * context. Each names its reason; tests assert it.
 */
final class TenantManager
{
    private ?TenantContext $context = null;

    /** @var list<string> */
    private array $systemReasons = [];

    public function set(TenantContext $context): void
    {
        $this->context = $context;
    }

    public function forget(): void
    {
        $this->context = null;
    }

    public function context(): ?TenantContext
    {
        return $this->context;
    }

    public function require(): TenantContext
    {
        return $this->context ?? throw TenancyViolation::missingContext('a tenant-scoped operation');
    }

    public function inSystemContext(): bool
    {
        return $this->systemReasons !== [];
    }

    /** The innermost active system reason, or null. */
    public function systemReason(): ?string
    {
        return $this->systemReasons === [] ? null : $this->systemReasons[array_key_last($this->systemReasons)];
    }

    /**
     * Run $callback with tenant scopes lifted. Nestable.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function system(string $reason, Closure $callback): mixed
    {
        $this->systemReasons[] = $reason;
        Log::debug('tenancy.system_context', ['reason' => $reason]);

        try {
            return $callback();
        } finally {
            array_pop($this->systemReasons);
        }
    }

    /**
     * Run $callback as $context (a queue job acting for a tenant), restoring
     * whatever was there before.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function actingAs(TenantContext $context, Closure $callback): mixed
    {
        $previous = $this->context;
        $this->context = $context;

        try {
            return $callback();
        } finally {
            $this->context = $previous;
        }
    }
}

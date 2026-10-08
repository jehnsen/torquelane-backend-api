<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Access\AccessMatrix;
use App\Domain\Access\Capability;
use App\Domain\Modules\Module;
use App\Domain\Tenancy\TenantContext;
use App\Tenancy\ModuleGate;
use App\Tenancy\TenantManager;
use Closure;
use Illuminate\Auth\Access\Response;

/**
 * Shared checks for every policy: capability + tenant scope (+ module
 * entitlement where a route belongs to a module, via ModuleGate).
 *
 * Order matters. Scope first: a record the caller cannot see is 404, exactly
 * like a missing one, whatever their capabilities, so a 403 never confirms
 * that another tenant's record exists. Then side (staff-only screens), then
 * the capability, whose denial carries the frontend's denialReason text.
 */
abstract class TenantPolicy
{
    protected function context(): TenantContext
    {
        return app(TenantManager::class)->require();
    }

    protected function notFound(): Response
    {
        return Response::denyAsNotFound();
    }

    protected function staffOnly(): ?Response
    {
        return $this->context()->isStaff() ? null : Response::deny('Only staff can do this.');
    }

    protected function capability(Capability $capability): ?Response
    {
        $context = $this->context();

        return $context->can($capability) ? null : Response::deny(AccessMatrix::denialReason($context->role, $capability));
    }

    /**
     * The first denial among the checks, in order; allow when none denies. A
     * Closure check runs only once every check before it has passed: module
     * checks THROW, and evaluated eagerly as an argument they would answer
     * 403 for a record the scope check was about to hide as 404.
     *
     * @param  Response|(Closure(): ?Response)|null  ...$checks
     */
    protected function first(Response|Closure|null ...$checks): Response
    {
        foreach ($checks as $check) {
            if ($check instanceof Closure) {
                $check = $check();
            }
            if ($check !== null) {
                return $check;
            }
        }

        return Response::allow();
    }

    /**
     * Module entitlement: throws 403 module_disabled (not a plain forbidden)
     * when the module is not active where the session works (or in
     * `$branchId`). Deferred, so it runs in its place within `first()`.
     *
     * @return Closure(): null
     */
    protected function module(Module $module, ?string $branchId = null): Closure
    {
        return function () use ($module, $branchId): null {
            app(ModuleGate::class)->ensure($module, $branchId);

            return null;
        };
    }

    /** 404 unless $visible. */
    protected function visible(bool $visible): ?Response
    {
        return $visible ? null : $this->notFound();
    }
}

<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Access\AccessMatrix;
use App\Domain\Access\Capability;
use App\Domain\Modules\Module;
use App\Domain\Tenancy\TenantContext;
use App\Tenancy\ModuleGate;
use App\Tenancy\TenantManager;
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
     * The first denial among the checks, in order; allow when none denies.
     */
    protected function first(?Response ...$checks): Response
    {
        foreach ($checks as $check) {
            if ($check !== null) {
                return $check;
            }
        }

        return Response::allow();
    }

    /**
     * Module entitlement: throws 403 module_disabled (not a plain forbidden)
     * when the module is not active where the session works.
     */
    protected function module(Module $module): ?Response
    {
        app(ModuleGate::class)->ensure($module);

        return null;
    }

    /** 404 unless $visible. */
    protected function visible(bool $visible): ?Response
    {
        return $visible ? null : $this->notFound();
    }
}

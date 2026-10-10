<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Access\Capability;
use App\Domain\Modules\Module;
use Illuminate\Auth\Access\Response;

/**
 * Detailing is staff-only and belongs to the `detailing` module. Every check
 * runs in the same order: side (a portal session is refused), then scope (a
 * record of a branch the caller is not allowed is 404, like a missing one),
 * then the module (403 `module_disabled` where the branch does not offer
 * detailing), then the capability.
 */
abstract class DetailingPolicy extends TenantPolicy
{
    /** A list or a create with no branch named yet: the module must be on somewhere the caller works. */
    protected function anywhere(Capability $capability): Response
    {
        return $this->first($this->staffOnly(), $this->module(Module::Detailing), $this->capability($capability));
    }

    /** Something that belongs to `$branchId`. */
    protected function in(string $branchId, Capability $capability): Response
    {
        return $this->first(
            $this->staffOnly(),
            $this->visible($this->context()->branchAllowed($branchId)),
            $this->module(Module::Detailing, $branchId),
            $this->capability($capability),
        );
    }
}

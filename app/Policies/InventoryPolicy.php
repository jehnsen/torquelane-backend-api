<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Access\Capability;
use Illuminate\Auth\Access\Response;

/**
 * The shop's stock room is staff-only. Reading needs `inventory:view`;
 * changing anything needs `inventory:manage`. Branch-owned records outside
 * the caller's allowed branches are 404 (scope first, then side, then
 * capability), like every other branch-owned record.
 */
abstract class InventoryPolicy extends TenantPolicy
{
    protected function reads(): Response
    {
        return $this->first($this->staffOnly(), $this->capability(Capability::InventoryView));
    }

    /** Read a record that belongs to `$branchId`. */
    protected function readsIn(string $branchId): Response
    {
        return $this->first(
            $this->staffOnly(),
            $this->visible($this->context()->branchAllowed($branchId)),
            $this->capability(Capability::InventoryView),
        );
    }

    /** Change a record that belongs to `$branchId`. */
    protected function managesIn(string $branchId): Response
    {
        return $this->first(
            $this->staffOnly(),
            $this->visible($this->context()->branchAllowed($branchId)),
            $this->capability(Capability::InventoryManage),
        );
    }

    /** Change organization-wide stock master data. */
    protected function manages(): Response
    {
        return $this->first($this->staffOnly(), $this->capability(Capability::InventoryManage));
    }
}

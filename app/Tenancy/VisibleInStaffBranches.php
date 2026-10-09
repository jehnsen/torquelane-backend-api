<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * For records owned by one branch (`branch_id`): staff see those of the
 * branches they are allowed, portal users none (the shop's stock room is not
 * a customer's business).
 */
trait VisibleInStaffBranches
{
    /**
     * @param  Builder<static>  $query
     */
    public function scopeVisibleTo(Builder $query, TenantContext $context): void
    {
        $query->whereIn($this->qualifyColumn('branch_id'), $context->isStaff() ? $context->allowedBranchIds : []);
    }
}

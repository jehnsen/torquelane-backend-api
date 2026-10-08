<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Domain\Tenancy\TenantContext;
use App\Models\Branch;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Which branches a staff member may be pinned to by the person granting access.
 *
 * An empty list means "every branch", the widest grant there is, so only an
 * unrestricted granter may give it. A granter pinned to some branches may
 * only pin people within those branches, and may only touch staff who work
 * entirely within them.
 */
final class BranchContainment
{
    /**
     * Validates and normalises the requested pins (sorted, unique).
     *
     * @param  list<string>  $requested
     * @return list<string>
     */
    public static function pins(TenantContext $granter, array $requested): array
    {
        $requested = array_values(array_unique($requested));
        sort($requested);

        if ($requested === [] && $granter->branchRestricted) {
            throw ValidationException::withMessages(['branch_ids' => 'Choose which of your branches this person works in.']);
        }

        $known = Branch::query()->whereIn('id', $requested)->pluck('id')->all();
        foreach ($requested as $id) {
            if (! in_array($id, $known, true) || ! $granter->branchAllowed($id)) {
                throw ValidationException::withMessages(['branch_ids' => 'You can only assign branches you have access to.']);
            }
        }

        return $requested;
    }

    /**
     * Whether the granter may manage a staff member currently pinned to
     * $current (empty = every branch).
     *
     * @param  list<string>  $current
     */
    public static function assertCovers(TenantContext $granter, array $current): void
    {
        if (! $granter->branchRestricted) {
            return;
        }
        if ($current === [] || array_diff($current, $granter->allowedBranchIds) !== []) {
            throw new AuthorizationException('This person works in branches outside yours.');
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Modules;

/**
 * A module is active for a branch only if it is enabled for the organization
 * AND for that branch. Either switch alone does nothing: an organization that
 * stops paying for a module turns it off everywhere at once, and a branch
 * that never offered a service keeps it off even while the organization has it.
 * A missing row is "disabled".
 */
final class ModuleEntitlements
{
    /**
     * @param  list<Module>  $organizationEnabled
     * @param  list<Module>  $branchEnabled
     * @return list<Module>
     */
    public static function activeForBranch(array $organizationEnabled, array $branchEnabled): array
    {
        return array_values(array_filter(
            Module::cases(),
            fn (Module $module): bool => in_array($module, $organizationEnabled, true) && in_array($module, $branchEnabled, true),
        ));
    }

    /**
     * Active anywhere in a set of branches: what a session working across
     * "all" its branches can reach somewhere.
     *
     * @param  list<Module>  $organizationEnabled
     * @param  array<string, list<Module>>  $enabledByBranch
     * @return list<Module>
     */
    public static function activeForAny(array $organizationEnabled, array $enabledByBranch): array
    {
        return array_values(array_filter(
            Module::cases(),
            function (Module $module) use ($organizationEnabled, $enabledByBranch): bool {
                foreach ($enabledByBranch as $branchEnabled) {
                    if (in_array($module, self::activeForBranch($organizationEnabled, $branchEnabled), true)) {
                        return true;
                    }
                }

                return false;
            },
        ));
    }
}

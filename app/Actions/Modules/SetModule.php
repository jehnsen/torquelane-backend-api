<?php

declare(strict_types=1);

namespace App\Actions\Modules;

use App\Actions\Audit\AuditTrail;
use App\Domain\Modules\Module;
use App\Models\Branch;
use App\Models\BranchModule;
use App\Models\OrganizationModule;
use Illuminate\Support\Facades\DB;

/**
 * Flips one module switch at the organization or branch level. Both must be
 * on for the module to be active in a branch (ModuleEntitlements); either
 * level can be switched independently.
 */
final class SetModule
{
    public function __construct(private readonly AuditTrail $audit) {}

    public function forOrganization(Module $module, bool $enabled): OrganizationModule
    {
        return DB::transaction(function () use ($module, $enabled): OrganizationModule {
            $row = OrganizationModule::query()->where('module', $module->value)->lockForUpdate()->first()
                ?? (new OrganizationModule)->forceFill(['module' => $module, 'enabled' => false]);
            $before = $row->exists ? AuditTrail::snapshot($row) : null;

            $row->forceFill(['enabled' => $enabled])->save();
            $this->audit->record($row, $enabled ? 'enabled' : 'disabled', $before, AuditTrail::snapshot($row));

            return $row;
        });
    }

    public function forBranch(Branch $branch, Module $module, bool $enabled): BranchModule
    {
        return DB::transaction(function () use ($branch, $module, $enabled): BranchModule {
            $row = BranchModule::query()->where('branch_id', $branch->id)->where('module', $module->value)->lockForUpdate()->first()
                ?? (new BranchModule)->forceFill(['branch_id' => $branch->id, 'module' => $module, 'enabled' => false]);
            $before = $row->exists ? AuditTrail::snapshot($row) : null;

            $row->forceFill(['enabled' => $enabled])->save();
            $this->audit->record($row, $enabled ? 'enabled' : 'disabled', $before, AuditTrail::snapshot($row));

            return $row;
        });
    }
}

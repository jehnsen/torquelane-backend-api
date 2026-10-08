<?php

declare(strict_types=1);

namespace App\Actions\WorkOrders;

use App\Actions\Audit\AuditTrail;
use App\Domain\Approvals\ApprovalSettings;
use App\Models\ApprovalSetting;
use App\Models\Branch;
use Illuminate\Support\Facades\DB;

/**
 * The organization's defaults (every field, a missing one keeps its current
 * value) and a branch's sparse override (null clears a field back to
 * inheriting). Customer-account overrides stay on the account (Phase 1).
 */
final class SaveApprovalSettings
{
    public function __construct(
        private readonly AuditTrail $audit,
        private readonly ApprovalSettingsResolver $resolver,
    ) {}

    /**
     * @param  array<string, mixed>  $values
     */
    public function organization(array $values): ApprovalSettings
    {
        return DB::transaction(function () use ($values): ApprovalSettings {
            $row = ApprovalSetting::query()->whereNull('branch_id')->lockForUpdate()->first() ?? new ApprovalSetting;
            $before = $row->exists ? AuditTrail::snapshot($row) : null;
            $next = $this->resolver->organizationDefaults()->overriddenBy(array_filter($values, fn (mixed $v): bool => $v !== null));

            $row->forceFill(['branch_id' => null] + $next->toArray())->save();
            $this->audit->record($row, $before === null ? 'created' : 'updated', $before, AuditTrail::snapshot($row));

            return $next;
        });
    }

    /**
     * @param  array<string, mixed>  $values  a null value clears the override
     */
    public function branch(Branch $branch, array $values): ApprovalSettings
    {
        return DB::transaction(function () use ($branch, $values): ApprovalSettings {
            $row = ApprovalSetting::query()->where('branch_id', $branch->id)->lockForUpdate()->first() ?? new ApprovalSetting;
            $before = $row->exists ? AuditTrail::snapshot($row) : null;

            $row->forceFill(['branch_id' => $branch->id] + array_intersect_key($values, array_flip(ApprovalSettings::KEYS)))->save();
            $this->audit->record($row, $before === null ? 'created' : 'updated', $before, AuditTrail::snapshot($row));

            return $this->resolver->forBranch($branch->id);
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Actions\Audit\AuditTrail;
use App\Domain\Approvals\ApprovalBands;
use App\Models\CustomerAccount;
use Illuminate\Support\Facades\DB;

/**
 * A customer account's sparse approval overrides (../web store.ts
 * updateApprovalSettings, client scope): each key named sets that band for
 * the account; a key sent as null goes back to inheriting. Every other key
 * is left as it was. No key left at all stores NULL (inherit everything).
 */
final class SetApprovalOverrides
{
    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * @param  array<string, int|string|null>  $changes  ApprovalBands::OVERRIDE_KEYS only
     */
    public function handle(CustomerAccount $account, array $changes): CustomerAccount
    {
        return DB::transaction(function () use ($account, $changes): CustomerAccount {
            $locked = CustomerAccount::query()->lockForUpdate()->findOrFail($account->id);
            $before = AuditTrail::snapshot($locked);

            $overrides = $locked->approval_threshold_overrides ?? [];
            foreach (array_intersect_key($changes, array_flip(ApprovalBands::OVERRIDE_KEYS)) as $key => $value) {
                if ($value === null) {
                    unset($overrides[$key]);
                } else {
                    $overrides[$key] = $value;
                }
            }

            $locked->forceFill(['approval_threshold_overrides' => $overrides === [] ? null : $overrides])->save();
            $this->audit->record($locked, 'approval_settings_updated', $before, AuditTrail::snapshot($locked));

            return $locked;
        });
    }
}

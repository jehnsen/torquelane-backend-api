<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Actions\Audit\AuditTrail;
use App\Domain\Tenancy\AccountStanding;
use App\Exceptions\InvalidTransitionException;
use App\Models\CustomerAccount;
use Illuminate\Support\Facades\DB;

/**
 * Suspend or reactivate. Suspending locks the account's portal users out at
 * once (their next request is 403 account_suspended) and stops new work;
 * staff keep read access. Reactivation restores both. Unlike the frontend,
 * where nothing could edit a suspended client afterwards, staff can reinstate
 * an account here.
 */
final class SetCustomerAccountStatus
{
    public function __construct(private readonly AuditTrail $audit) {}

    public function handle(CustomerAccount $account, string $status): CustomerAccount
    {
        return DB::transaction(function () use ($account, $status): CustomerAccount {
            $locked = CustomerAccount::query()->lockForUpdate()->findOrFail($account->id);

            if ($locked->status === $status) {
                throw new InvalidTransitionException(
                    $status === AccountStanding::SUSPENDED ? 'This account is already suspended.' : 'This account is already active.',
                    ['from' => $locked->status, 'to' => $status],
                );
            }

            $before = AuditTrail::snapshot($locked);
            $locked->forceFill(['status' => $status])->save();
            $this->audit->record(
                $locked,
                $status === AccountStanding::SUSPENDED ? 'suspended' : 'reactivated',
                $before,
                AuditTrail::snapshot($locked),
            );

            return $locked;
        });
    }
}

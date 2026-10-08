<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Actions\Audit\AuditTrail;
use App\Models\CustomerAccount;
use Illuminate\Support\Facades\DB;

/**
 * Edits an account's details. Allowed while suspended (keeping a collections
 * address current is not new work). Status changes go through
 * SetCustomerAccountStatus; the account type never changes.
 */
final class UpdateCustomerAccount
{
    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * @param  array<string, mixed>  $attributes  validated by UpdateCustomerAccountRequest
     */
    public function handle(CustomerAccount $account, array $attributes): CustomerAccount
    {
        return DB::transaction(function () use ($account, $attributes): CustomerAccount {
            $locked = CustomerAccount::query()->lockForUpdate()->findOrFail($account->id);
            $before = AuditTrail::snapshot($locked);

            $locked->forceFill($attributes)->save();
            $this->audit->record($locked, 'updated', $before, AuditTrail::snapshot($locked));

            return $locked;
        });
    }
}

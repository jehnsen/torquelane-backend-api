<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Accounts\CreateCustomerAccount;
use App\Actions\Accounts\SetCustomerAccountStatus;
use App\Actions\Accounts\UpdateCustomerAccount;
use App\Actions\Directory\DirectoryQueries;
use App\Domain\Tenancy\AccountStanding;
use App\Http\Requests\ListCustomerAccountsRequest;
use App\Http\Requests\SaveCustomerAccountRequest;
use App\Http\Resources\CustomerAccountCollection;
use App\Http\Resources\CustomerAccountResource;
use App\Models\CustomerAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Staff see every account in the organization, suspended ones included. A
 * portal user sees exactly their own; any other id is 404.
 */
final class CustomerAccountController
{
    /**
     * List customer accounts
     */
    public function index(ListCustomerAccountsRequest $request, DirectoryQueries $queries): CustomerAccountCollection
    {
        Gate::authorize('viewAny', CustomerAccount::class);

        /** @var array{status?: string, account_type?: string, q?: string} $filters */
        $filters = $request->safe()->only(['status', 'account_type', 'q']);

        return new CustomerAccountCollection($queries->customerAccounts($filters, $request->perPage()));
    }

    /**
     * Open a customer account
     *
     * Staff with `customer:manage`. `consents` must include `service_records`,
     * granted; it is recorded in the same transaction as the account.
     */
    public function store(SaveCustomerAccountRequest $request, CreateCustomerAccount $create): JsonResponse
    {
        Gate::authorize('create', CustomerAccount::class);

        $account = $create->handle($request->accountAttributes(), $request->openingConsents());

        return (new CustomerAccountResource($account))->response()->setStatusCode(201);
    }

    /**
     * Show a customer account
     */
    public function show(CustomerAccount $customerAccount): CustomerAccountResource
    {
        Gate::authorize('view', $customerAccount);

        return new CustomerAccountResource($customerAccount);
    }

    /**
     * Update a customer account
     *
     * `customer:manage`. Portal users may edit only their own account's
     * contact details and branding.
     */
    public function update(SaveCustomerAccountRequest $request, CustomerAccount $customerAccount, UpdateCustomerAccount $update): CustomerAccountResource
    {
        Gate::authorize('update', $customerAccount);

        return new CustomerAccountResource($update->handle($customerAccount, $request->accountAttributes()));
    }

    /**
     * Suspend a customer account
     *
     * Staff with `settings:manage`. The account's portal users are locked out
     * at once and it takes no new work; staff can still read it.
     */
    public function suspend(CustomerAccount $customerAccount, SetCustomerAccountStatus $set): CustomerAccountResource
    {
        Gate::authorize('setStatus', $customerAccount);

        return new CustomerAccountResource($set->handle($customerAccount, AccountStanding::SUSPENDED));
    }

    /**
     * Reactivate a customer account
     *
     * Staff with `settings:manage`.
     */
    public function reactivate(CustomerAccount $customerAccount, SetCustomerAccountStatus $set): CustomerAccountResource
    {
        Gate::authorize('setStatus', $customerAccount);

        return new CustomerAccountResource($set->handle($customerAccount, AccountStanding::ACTIVE));
    }
}

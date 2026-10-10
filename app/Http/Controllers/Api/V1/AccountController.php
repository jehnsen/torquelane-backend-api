<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Ledger\LedgerQueries;
use App\Actions\Ledger\ManageAccounts;
use App\Actions\Ledger\PostingRules;
use App\Domain\Ledger\RuleKey;
use App\Http\Requests\LedgerRangeRequest;
use App\Http\Requests\LedgerSettingsRequest;
use App\Http\Requests\SaveAccountRequest;
use App\Http\Requests\SaveExportMappingsRequest;
use App\Http\Requests\UpdatePostingRulesRequest;
use App\Http\Resources\AccountResource;
use App\Models\Account;
use App\Models\AccountExportMapping;
use App\Models\Organization;
use App\Tenancy\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The chart of accounts, the posting rules and the export settings (Phase 8).
 * Reading needs `ledger:view`; changing needs `ledger:manage` (organization admins).
 */
final class AccountController
{
    /**
     * Chart of accounts
     *
     * `ledger:view` (staff). Every account with its balance through `as_of`
     * (default today) over the caller's branches, and the posting rules that
     * point at it. The chart is installed on first use.
     */
    public function index(LedgerRangeRequest $request, LedgerQueries $ledger): JsonResponse
    {
        Gate::authorize('viewAny', Account::class);

        return new JsonResponse(['data' => $ledger->chart($request->asOf()), 'meta' => ['as_of' => $request->asOf(), 'scope' => $ledger->scope()]]);
    }

    /**
     * Add an account
     *
     * `ledger:manage`. A new account takes no postings until a posting rule
     * is pointed at it. Its code is unique in the chart.
     */
    public function store(SaveAccountRequest $request, ManageAccounts $accounts): JsonResponse
    {
        Gate::authorize('create', Account::class);

        return (new AccountResource($accounts->create($request->newAccount())))->response()->setStatusCode(201);
    }

    /**
     * Edit an account
     *
     * `ledger:manage`. Rename or describe it, or deactivate one no posting
     * rule uses. Once an account has been posted to its code, type and side
     * are fixed (409).
     */
    public function update(SaveAccountRequest $request, Account $account, ManageAccounts $accounts): AccountResource
    {
        Gate::authorize('update', $account);

        return new AccountResource($accounts->update($account, $request->changes()));
    }

    /**
     * Posting rules
     *
     * `ledger:view`. Each event or category the books post by, the account it
     * posts to and the account type it needs.
     */
    public function rules(PostingRules $rules, TenantManager $tenancy): JsonResponse
    {
        Gate::authorize('viewAny', Account::class);
        $map = $rules->map($tenancy->require()->organizationId());
        $accounts = Account::query()->whereIn('id', array_values($map))->get()->keyBy('id');

        return new JsonResponse(['data' => array_map(function (RuleKey $key) use ($map, $accounts): array {
            $account = $accounts->get($map[$key->value] ?? '');

            return [
                'key' => $key->value,
                'label' => $key->label(),
                'group' => $key->group(),
                'account_type' => $key->accountType()->value,
                'account_id' => $account?->id,
                'account_code' => $account?->code,
                'account_name' => $account?->name,
                'default_code' => $key->defaultCode(),
            ];
        }, RuleKey::cases())]);
    }

    /**
     * Edit posting rules
     *
     * `ledger:manage`. Re-point rules at other accounts of the right type.
     * It applies to postings from now on; entries already made are never
     * rewritten.
     */
    public function updateRules(UpdatePostingRulesRequest $request, PostingRules $rules): JsonResponse
    {
        Gate::authorize('configure', Account::class);
        $rules->update($request->changes());

        return new JsonResponse(null, 204);
    }

    /**
     * Ledger settings
     *
     * `ledger:view`. The accounting product the books are exported for
     * (`none`, `xero` or `quickbooks`) and the account mappings its import
     * needs.
     */
    public function settings(TenantManager $tenancy): JsonResponse
    {
        Gate::authorize('viewAny', Account::class);

        return new JsonResponse(['data' => [
            'accounting_target' => Organization::query()->findOrFail($tenancy->require()->organizationId())->accounting_target,
            'mappings' => array_values(AccountExportMapping::query()->orderBy('target')->get()->map(fn (AccountExportMapping $m): array => [
                'account_id' => $m->account_id,
                'target' => $m->target,
                'external_code' => $m->external_code,
                'external_name' => $m->external_name,
            ])->all()),
        ]]);
    }

    /**
     * Set the accounting product
     *
     * `ledger:manage`. `xero` and `quickbooks` switch on that product's
     * manual-journal import file next to the plain CSV.
     */
    public function updateSettings(LedgerSettingsRequest $request, ManageAccounts $accounts): JsonResponse
    {
        Gate::authorize('configure', Account::class);

        return new JsonResponse(['data' => ['accounting_target' => $accounts->setAccountingTarget($request->target())->accounting_target]]);
    }

    /**
     * Map accounts for export
     *
     * `ledger:manage`. Our account → the code (Xero) or name (QuickBooks
     * Online) the accountant's books use; an empty code and name removes the
     * mapping.
     */
    public function updateMappings(SaveExportMappingsRequest $request, ManageAccounts $accounts): JsonResponse
    {
        Gate::authorize('configure', Account::class);
        $accounts->mapForExport($request->mappings());

        return new JsonResponse(null, 204);
    }
}

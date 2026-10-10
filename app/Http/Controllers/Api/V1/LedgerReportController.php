<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Ledger\LedgerQueries;
use App\Http\Requests\LedgerRangeRequest;
use App\Models\Account;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Accounting reports (Phase 8), all `ledger:view` and all over the caller's
 * branches: with no branch picked (and none pinned) a report is the
 * consolidated one. They read the journal and nothing else.
 */
final class LedgerReportController
{
    /**
     * Trial balance
     *
     * Every account with activity through `as_of` (default today), its net
     * balance in the debit or credit column, and whether the columns agree.
     */
    public function trialBalance(LedgerRangeRequest $request, LedgerQueries $ledger): JsonResponse
    {
        Gate::authorize('viewAny', Account::class);

        return new JsonResponse(['data' => $ledger->trialBalance($request->asOf())]);
    }

    /**
     * General ledger for an account
     *
     * One account's lines over `from`..`to` (default this month) with a
     * running balance on the account's own side after the balance brought
     * forward. Paged.
     */
    public function generalLedger(LedgerRangeRequest $request, Account $account, LedgerQueries $ledger): JsonResponse
    {
        Gate::authorize('view', $account);

        return new JsonResponse(['data' => $ledger->generalLedger($account, $request->from(), $request->to(), $request->page(), $request->perPage())]);
    }

    /**
     * Profit and loss
     *
     * Revenue (net of discounts), cost of sales, gross profit, other expenses
     * and net profit over `from`..`to`, with a column per branch in scope and
     * the consolidated total.
     */
    public function profitAndLoss(LedgerRangeRequest $request, LedgerQueries $ledger): JsonResponse
    {
        Gate::authorize('viewAny', Account::class);

        return new JsonResponse(['data' => $ledger->profitAndLoss($request->from(), $request->to())]);
    }

    /**
     * Balance sheet
     *
     * Assets, liabilities and equity through `as_of`, including the earnings
     * made to date; `balanced` says whether assets = liabilities + equity.
     */
    public function balanceSheet(LedgerRangeRequest $request, LedgerQueries $ledger): JsonResponse
    {
        Gate::authorize('viewAny', Account::class);

        return new JsonResponse(['data' => $ledger->balanceSheet($request->asOf())]);
    }

    /**
     * Daily sales
     *
     * By day and branch over `from`..`to`: net sales, VAT and the invoiced
     * total (voids netted on the day they happened), and the money received
     * that day by payment method.
     */
    public function dailySales(LedgerRangeRequest $request, LedgerQueries $ledger): JsonResponse
    {
        Gate::authorize('viewAny', Account::class);

        return new JsonResponse(['data' => $ledger->dailySales($request->from(), $request->to())]);
    }
}

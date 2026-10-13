<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Ledger\ManageBankReconciliations;
use App\Http\Requests\BankReconciliationRequest;
use App\Http\Requests\PaginatedRequest;
use App\Models\Account;
use App\Models\BankReconciliation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Bank reconciliation (Phase 12): agreeing a cash-side account with the
 * statement of whoever holds the money. Whole-organization, so every action
 * here needs a session that is not branch-limited.
 */
final class BankReconciliationController
{
    /**
     * List bank reconciliations
     *
     * `ledger:view`. Newest statement first.
     */
    public function index(PaginatedRequest $request, ManageBankReconciliations $reconciliations): JsonResponse
    {
        Gate::authorize('viewAny', BankReconciliation::class);
        $page = BankReconciliation::query()->with('account')->orderByDesc('statement_date')->orderByDesc('id')->paginate($request->perPage());

        return new JsonResponse([
            'data' => array_map(fn (BankReconciliation $r): array => $this->present($r, $reconciliations, false), array_values($page->items())),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    /**
     * Open a bank reconciliation
     *
     * `ledger:manage`. `account_id` (a cash, bank, wallet or clearing
     * account), `statement_date`, `statement_balance_cents` (the statement's
     * closing balance; negative when overdrawn). One open at a time per
     * account, dated after the last one completed.
     */
    public function store(BankReconciliationRequest $request, ManageBankReconciliations $reconciliations): JsonResponse
    {
        Gate::authorize('manage', BankReconciliation::class);
        $account = Account::query()->findOrFail($request->accountId());

        return new JsonResponse(['data' => $this->present($reconciliations->open($account, $request->statement()), $reconciliations, true)], 201);
    }

    /**
     * Show a bank reconciliation
     *
     * The statement against the books: what cleared on earlier statements,
     * what is ticked on this one, the difference still to explain, what is
     * outstanding, and every line the statement could show.
     */
    public function show(BankReconciliation $bank_reconciliation, ManageBankReconciliations $reconciliations): JsonResponse
    {
        Gate::authorize('view', $bank_reconciliation);

        return new JsonResponse(['data' => $this->present($bank_reconciliation, $reconciliations, true)]);
    }

    /**
     * Tick lines against the statement
     *
     * `ledger:manage`. `clear`: journal line ids the statement shows;
     * `unclear`: ids ticked by mistake. A line clears once, ever.
     */
    public function lines(BankReconciliationRequest $request, BankReconciliation $bank_reconciliation, ManageBankReconciliations $reconciliations): JsonResponse
    {
        Gate::authorize('manage', BankReconciliation::class);

        return new JsonResponse(['data' => $this->present($reconciliations->tick($bank_reconciliation, $request->clear(), $request->unclear()), $reconciliations, true)]);
    }

    /**
     * Complete a bank reconciliation
     *
     * `ledger:manage`. Only when the statement balance equals everything
     * cleared (409 `statement_does_not_agree` otherwise, with the
     * difference). Final.
     */
    public function complete(BankReconciliation $bank_reconciliation, ManageBankReconciliations $reconciliations): JsonResponse
    {
        Gate::authorize('manage', BankReconciliation::class);

        return new JsonResponse(['data' => $this->present($reconciliations->complete($bank_reconciliation), $reconciliations, true)]);
    }

    /**
     * Discard an open bank reconciliation
     *
     * `ledger:manage`. A completed one is final.
     */
    public function destroy(BankReconciliation $bank_reconciliation, ManageBankReconciliations $reconciliations): Response
    {
        Gate::authorize('manage', BankReconciliation::class);
        $reconciliations->discard($bank_reconciliation);

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(BankReconciliation $reconciliation, ManageBankReconciliations $reconciliations, bool $withLines): array
    {
        $reconciliation->loadMissing('account');
        $statement = $reconciliations->statement($reconciliation);

        return [
            'id' => $reconciliation->id,
            'account_id' => $reconciliation->account_id,
            'account_code' => $reconciliation->account->code,
            'account_name' => $reconciliation->account->name,
            'statement_date' => $reconciliation->statement_date->toDateString(),
            'statement_balance_cents' => $reconciliation->statement_balance_cents,
            'status' => $reconciliation->status,
            'notes' => $reconciliation->notes,
            // Money in is positive: debits less credits on the account.
            'cleared_before_cents' => $statement->clearedBeforeCents,
            'ticked_cents' => $statement->tickedCents,
            'cleared_cents' => $statement->clearedCents(),
            'difference_cents' => $statement->differenceCents(),
            'outstanding_cents' => $statement->outstandingCents,
            'book_balance_cents' => $statement->bookBalanceCents(),
            'agrees' => $statement->agrees(),
            'created_by_name' => $reconciliation->created_by_name,
            'completed_at' => $reconciliation->completed_at?->toIso8601ZuluString(),
            'completed_by_name' => $reconciliation->completed_by_name,
            'created_at' => $reconciliation->created_at->toIso8601ZuluString(),
        ] + ($withLines ? ['lines' => $reconciliations->lines($reconciliation)] : []);
    }
}

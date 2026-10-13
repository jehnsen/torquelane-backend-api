<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Billing\BillingBranch;
use App\Actions\Ledger\ManageManualJournals;
use App\Http\Requests\PaginatedRequest;
use App\Http\Requests\PostManualJournalRequest;
use App\Http\Requests\VoidDocumentRequest;
use App\Http\Resources\ManualJournalCollection;
use App\Http\Resources\ManualJournalResource;
use App\Models\ManualJournal;
use App\Tenancy\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Journal vouchers (Phase 12): the entries no document makes by itself (a
 * bank charge, interest, a wallet swept to the bank, an accrual). Posted
 * once, reversed at most once, never edited.
 */
final class ManualJournalController
{
    /**
     * List journal vouchers
     *
     * `ledger:view`. Newest first, in the caller's branches.
     */
    public function index(PaginatedRequest $request, TenantManager $tenancy): ManualJournalCollection
    {
        Gate::authorize('viewAny', ManualJournal::class);

        return new ManualJournalCollection(ManualJournal::query()
            ->whereIn('branch_id', $tenancy->require()->branchFilter())
            ->orderByDesc('entry_date')->orderByDesc('id')
            ->paginate($request->perPage()));
    }

    /**
     * Post a journal voucher
     *
     * `ledger:manage`; requires an `Idempotency-Key`. `memo`, `entry_date`
     * (default today; never in a closed month), `reference`, and at least two
     * `lines`: `account_id` with `debit_cents` or `credit_cents`. Debits
     * equal credits. A control account (receivables, inventory, customer
     * deposits, payables, GR/IR, unearned revenue) is refused: only its own
     * documents post to it.
     */
    public function store(PostManualJournalRequest $request, ManageManualJournals $journals, BillingBranch $branches): JsonResponse
    {
        $branchId = $branches->resolve($request->branchId());
        Gate::authorize('create', [ManualJournal::class, $branchId]);

        return (new ManualJournalResource($journals->post(['branch_id' => $branchId] + $request->voucher())))->response()->setStatusCode(201);
    }

    /**
     * Show a journal voucher
     *
     * Its lines are its journal entry's: `GET /ledger/journal?source_id=`.
     */
    public function show(ManualJournal $manual_journal): ManualJournalResource
    {
        Gate::authorize('view', $manual_journal);

        return new ManualJournalResource($manual_journal);
    }

    /**
     * Reverse a journal voucher
     *
     * `ledger:manage`, with a reason. Posts the mirror entry, dated today.
     */
    public function reverse(VoidDocumentRequest $request, ManualJournal $manual_journal, ManageManualJournals $journals): ManualJournalResource
    {
        Gate::authorize('reverse', $manual_journal);

        return new ManualJournalResource($journals->reverse($manual_journal, $request->reason()));
    }
}

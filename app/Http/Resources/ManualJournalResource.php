<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Access\Capability;
use App\Models\ManualJournal;
use App\Tenancy\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A journal voucher. Its lines are its journal entry's: read them at
 * `GET /ledger/journal?source_id=<id>`.
 *
 * @property ManualJournal $resource
 */
final class ManualJournalResource extends JsonResource
{
    public function __construct(ManualJournal $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $journal = $this->resource;
        $can = app(TenantManager::class)->context()?->can(Capability::LedgerManage) ?? false;

        return [
            'id' => $journal->id,
            'branch_id' => $journal->branch_id,
            'entry_date' => $journal->entry_date->toDateString(),
            'reference' => $journal->reference,
            'memo' => $journal->memo,
            'status' => $journal->status,
            'total_cents' => $journal->total_cents,
            'created_by_name' => $journal->created_by_name,
            'reversed_at' => $journal->reversed_at?->toIso8601ZuluString(),
            'reversed_by_name' => $journal->reversed_by_name,
            'reversal_reason' => $journal->reversal_reason,
            'can_reverse' => $journal->status === ManualJournal::POSTED && $can,
            'created_at' => $journal->created_at->toIso8601ZuluString(),
        ];
    }
}

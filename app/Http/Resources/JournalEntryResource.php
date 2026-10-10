<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One journal entry with its lines. Money in centavos. A reversal names the
 * entry it undoes, and an undone entry names its reversal.
 *
 * @property JournalEntry $resource
 */
final class JournalEntryResource extends JsonResource
{
    public function __construct(JournalEntry $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $entry = $this->resource;
        $entry->loadMissing(['lines.account', 'branch', 'period']);

        return [
            'id' => $entry->id,
            'number' => $entry->number,
            'entry_date' => $entry->entry_date->toDateString(),
            'period' => $entry->period->period_key,
            'period_closed' => ! $entry->period->isOpen(),
            'event' => $entry->event->value,
            'event_label' => $entry->event->label(),
            'is_reversal' => $entry->event->isReversal(),
            'source_type' => $entry->source_type,
            'source_id' => $entry->source_id,
            'reference' => $entry->reference,
            'memo' => $entry->memo,
            'payment_method' => $entry->payment_method,
            'branch_id' => $entry->branch_id,
            'branch_name' => $entry->branch->name,
            'counter_branch_id' => $entry->counter_branch_id,
            'reversal_of_id' => $entry->reversal_of_id,
            'reversed_by_id' => $entry->relationLoaded('reversedBy') ? $entry->reversedBy?->id : null,
            'total_cents' => $entry->total_cents,
            'posted_by_name' => $entry->posted_by_name,
            'posted_at' => $entry->posted_at->toIso8601ZuluString(),
            'lines' => array_values($entry->lines->map(fn (JournalLine $line): array => [
                'id' => $line->id,
                'account_id' => $line->account_id,
                'account_code' => $line->account->code,
                'account_name' => $line->account->name,
                'branch_id' => $line->branch_id,
                'debit_cents' => $line->debit_cents,
                'credit_cents' => $line->credit_cents,
                'customer_account_id' => $line->customer_account_id,
                'memo' => $line->memo,
            ])->all()),
        ];
    }
}

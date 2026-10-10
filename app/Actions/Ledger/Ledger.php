<?php

declare(strict_types=1);

namespace App\Actions\Ledger;

use App\Actions\Numbering\DocumentNumbers;
use App\Database\Cell;
use App\Domain\Ledger\AccountRef;
use App\Domain\Ledger\JournalDraft;
use App\Domain\Ledger\LedgerEvent;
use App\Domain\Ledger\Periods;
use App\Domain\Ledger\PeriodStatus;
use App\Domain\Ledger\PostingLine;
use App\Domain\Ledger\Postings\Postings;
use App\Domain\Ledger\Postings\ReversalFacts;
use App\Domain\Numbering\DocumentType;
use App\Domain\Shared\Calendar;
use App\Exceptions\ConflictException;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Period;
use App\Models\User;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * THE journal writer (R7, R8). Inside the event's own transaction it takes a
 * balanced draft (a draft cannot be built unbalanced), finds or opens its
 * period (refusing a closed one), numbers the entry from the `journal_entry`
 * series, and appends the entry and its lines. The database re-checks, at
 * COMMIT, that the entry balances.
 *
 * It names the organization from the event's own document, never from the
 * session, so a stock move posted from a job or a script (no signed-in user)
 * posts to the right books.
 *
 * Reversing never rewrites history: a void posts the mirror image, dated the
 * day it happened, in the period that day falls in. An original in a closed
 * period stays exactly as it was.
 */
final class Ledger
{
    private bool $backfilling = false;

    /** @var array<string, string> organization id → name of the posting user cached for the request */
    private array $actorNames = [];

    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly PostingRules $rules,
        private readonly DocumentNumbers $numbers,
    ) {}

    public function post(string $organizationId, JournalDraft $draft): JournalEntry
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Journal entries are posted inside the event\'s transaction.');
        }

        $period = $this->openPeriod($organizationId, $draft->entryDate);
        $accounts = $this->rules->map($organizationId);
        $now = CarbonImmutable::now();
        [$userId, $userName] = $this->actor();

        $entry = new JournalEntry;
        $entry->forceFill([
            'organization_id' => $organizationId,
            'branch_id' => $draft->branchId,
            'counter_branch_id' => $draft->counterBranchId,
            'number' => $this->numbers->issue($organizationId, null, DocumentType::JournalEntry, $now)->formatted,
            'entry_date' => $draft->entryDate,
            'period_id' => $period->id,
            'event' => $draft->event,
            'source_type' => $draft->sourceType(),
            'source_id' => $draft->sourceId,
            'reference' => $draft->reference,
            'memo' => $draft->memo,
            'payment_method' => $draft->paymentMethod,
            'reversal_of_id' => $draft->reversalOfId,
            'total_cents' => $draft->totalCents,
            'posted_by' => $userId,
            'posted_by_name' => $userName,
            'posted_at' => $now,
        ])->save();

        $rows = [];
        foreach ($draft->lines as $position => $line) {
            $accountId = $line->account->accountId ?? ($line->account->rule === null ? null : ($accounts[$line->account->rule->value] ?? null));
            if ($accountId === null) {
                throw new LogicException('A posting line names no account.');
            }
            $rows[] = [
                'id' => strtolower((string) Str::ulid()),
                'organization_id' => $organizationId,
                'journal_entry_id' => $entry->id,
                'position' => $position,
                'account_id' => $accountId,
                'branch_id' => $line->branchId,
                'debit_cents' => $line->debitCents,
                'credit_cents' => $line->creditCents,
                'customer_account_id' => $line->customerAccountId,
                'stock_move_id' => $line->stockMoveId,
                'memo' => mb_substr($line->memo, 0, 200),
                'created_at' => $now,
            ];
        }
        JournalLine::query()->insert($rows);

        return $entry;
    }

    /**
     * Post the mirror image of an entry, dated today (Manila) unless a date
     * is given (the backfill dates a void the day it really happened).
     */
    public function reverse(JournalEntry $original, LedgerEvent $event, string $sourceId, string $reason, ?string $on = null): JournalEntry
    {
        $original->loadMissing('period');
        $lines = array_values($original->lines()->get()->map(fn (JournalLine $line): PostingLine => new PostingLine(
            AccountRef::id($line->account_id),
            $line->debit_cents,
            $line->credit_cents,
            $line->branch_id,
            $line->customer_account_id,
            null,
            $line->memo,
        ))->all());

        // Say so when the entry it undoes sits in a month that is already closed.
        $reversed = $original->period->status === PeriodStatus::Closed
            ? sprintf('%s (%s, closed)', $original->number, Periods::label($original->period->period_key))
            : $original->number;

        $draft = Postings::postingsFor(new ReversalFacts(
            $event,
            $sourceId,
            $original->id,
            $reversed,
            $original->reference,
            $original->branch_id,
            $original->counter_branch_id,
            $original->payment_method,
            $on ?? Calendar::toDate(CarbonImmutable::now()),
            $reason,
            $lines,
        ));

        return $this->post($original->organization_id, $draft);
    }

    /**
     * Run $work with entries marked as posted by the backfill, not by whoever runs it.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function backfilling(Closure $work): mixed
    {
        $this->backfilling = true;

        try {
            return $work();
        } finally {
            $this->backfilling = false;
        }
    }

    /**
     * The month's period row, opened on first use, held against a concurrent
     * close (shared lock); a closed one refuses the posting.
     */
    public function openPeriod(string $organizationId, string $date): Period
    {
        $key = Periods::keyOf($date);
        $now = CarbonImmutable::now('UTC');
        ['starts_on' => $starts, 'ends_on' => $ends] = Periods::bounds($key);

        Period::query()->insertOrIgnore([
            'id' => strtolower((string) Str::ulid()),
            'organization_id' => $organizationId,
            'period_key' => $key,
            'starts_on' => $starts,
            'ends_on' => $ends,
            'status' => PeriodStatus::Open->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $period = Period::query()->where('organization_id', $organizationId)->where('period_key', $key)->sharedLock()->firstOrFail();
        if (! $period->isOpen()) {
            throw new ConflictException(
                sprintf('%s is closed; nothing is posted into it. Dated %s.', Periods::label($key), $date),
                ['reason' => 'period_closed', 'period' => $key, 'entry_date' => $date],
            );
        }

        return $period;
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private function actor(): array
    {
        if ($this->backfilling) {
            return [null, 'Backfill'];
        }
        $userId = $this->tenancy->context()?->userId;
        if ($userId === null) {
            return [null, 'System'];
        }

        $this->actorNames[$userId] ??= Cell::string(User::query()->whereKey($userId)->value('name')) ?: 'System';

        return [$userId, $this->actorNames[$userId]];
    }
}

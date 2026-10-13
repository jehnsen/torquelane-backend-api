<?php

declare(strict_types=1);

namespace App\Actions\Ledger;

use App\Actions\Audit\AuditTrail;
use App\Domain\Ledger\AccountRef;
use App\Domain\Ledger\PostingLine;
use App\Domain\Shared\Calendar;
use App\Domain\Shared\WebFormat;
use App\Exceptions\InvalidTransitionException;
use App\Models\Account;
use App\Models\ManualJournal;
use App\Models\User;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Journal vouchers: the entries no document makes by itself. A bank charge or
 * interest found on a statement, a wallet or card settlement swept into the
 * bank, an accrual, depreciation, a year-end adjustment.
 *
 * post: at least two lines, each a debit or a credit on an active account,
 * debits equal to credits, dated a day that is not in a closed month. A
 * control account (receivables, inventory, customer deposits, payables,
 * GR/IR, unearned revenue) is refused: its balance is its subledger's, and
 * only that subledger's documents move it. The voucher row and its journal
 * entry are written in one transaction (R3).
 *
 * reverse: R7. The mirror entry, dated today; the voucher is marked reversed,
 * once.
 *
 * @phpstan-type VoucherLine array{account_id: string, debit_cents?: int|null, credit_cents?: int|null, branch_id?: string|null, memo?: string|null}
 */
final class ManageManualJournals
{
    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly LedgerPostings $postings,
        private readonly PostingRules $rules,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @param  array{branch_id: string, entry_date?: string|null, reference?: string|null, memo: string, lines: list<VoucherLine>}  $data  validated
     */
    public function post(array $data): ManualJournal
    {
        return DB::transaction(function () use ($data): ManualJournal {
            $context = $this->tenancy->require();
            $today = Calendar::toDate(CarbonImmutable::now());
            $entryDate = $data['entry_date'] ?? $today;
            if ($entryDate > $today) {
                throw ValidationException::withMessages(['entry_date' => 'A journal voucher is never dated in the future.']);
            }

            $control = $this->rules->controlAccountIds($context->organizationId());
            $accounts = Account::query()->whereIn('id', array_map(fn (array $line): string => $line['account_id'], $data['lines']))->get()->keyBy('id');
            $lines = [];
            $debits = 0;
            $credits = 0;
            foreach ($data['lines'] as $position => $line) {
                $field = "lines.{$position}";
                $account = $accounts->get($line['account_id']);
                if (! $account instanceof Account || $account->organization_id !== $context->organizationId()) {
                    throw ValidationException::withMessages(["{$field}.account_id" => 'Unknown account.']);
                }
                if (! $account->is_active) {
                    throw ValidationException::withMessages(["{$field}.account_id" => "{$account->name} is inactive; it takes no new postings."]);
                }
                if (in_array($account->id, $control, true)) {
                    throw ValidationException::withMessages(["{$field}.account_id" => "{$account->name} is a control account; only its own documents post to it."]);
                }
                $debit = (int) ($line['debit_cents'] ?? 0);
                $credit = (int) ($line['credit_cents'] ?? 0);
                if (($debit > 0) === ($credit > 0)) {
                    throw ValidationException::withMessages([$field => 'A line is a debit or a credit: one of them, and more than nothing.']);
                }
                $branchId = $line['branch_id'] ?? $data['branch_id'];
                if (! $context->branchAllowed($branchId)) {
                    throw ValidationException::withMessages(["{$field}.branch_id" => 'That is not one of your branches.']);
                }
                $lines[] = new PostingLine(AccountRef::id($account->id), $debit, $credit, $branchId, null, null, trim((string) ($line['memo'] ?? '')) ?: $data['memo']);
                $debits += $debit;
                $credits += $credit;
            }
            if (count($lines) < 2) {
                throw ValidationException::withMessages(['lines' => 'A journal voucher has at least two lines.']);
            }
            if ($debits !== $credits) {
                throw ValidationException::withMessages(['lines' => sprintf('Debits (%s) and credits (%s) must be equal.', WebFormat::pesos($debits), WebFormat::pesos($credits))]);
            }

            $actor = User::query()->findOrFail($context->userId);
            $journal = new ManualJournal;
            $journal->forceFill([
                'branch_id' => $data['branch_id'],
                'entry_date' => $entryDate,
                'reference' => trim((string) ($data['reference'] ?? '')),
                'memo' => $data['memo'],
                'status' => ManualJournal::POSTED,
                'total_cents' => $debits,
                'created_by' => $actor->id,
                'created_by_name' => $actor->name,
            ])->save();

            $this->postings->manualJournal($journal, $lines);
            $this->audit->record($journal, 'posted', null, AuditTrail::snapshot($journal));

            return $journal;
        });
    }

    public function reverse(ManualJournal $journal, string $reason): ManualJournal
    {
        return DB::transaction(function () use ($journal, $reason): ManualJournal {
            $locked = ManualJournal::query()->lockForUpdate()->findOrFail($journal->id);
            if ($locked->status !== ManualJournal::POSTED) {
                throw new InvalidTransitionException('That journal voucher is already reversed.');
            }

            $before = AuditTrail::snapshot($locked);
            $actor = User::query()->findOrFail($this->tenancy->require()->userId);
            $locked->forceFill([
                'status' => ManualJournal::REVERSED,
                'reversed_at' => CarbonImmutable::now(),
                'reversed_by_name' => $actor->name,
                'reversal_reason' => $reason,
            ])->save();
            $this->postings->manualJournalReversed($locked, $reason);
            $this->audit->record($locked, 'reversed', $before, AuditTrail::snapshot($locked));

            return $locked;
        });
    }
}

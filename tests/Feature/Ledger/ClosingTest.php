<?php

declare(strict_types=1);

use App\Models\JournalEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\World;

/*
 * Closing the books (Phase 12): journal vouchers, bank reconciliation and the
 * year-end close, over the demo seed (today 2026-10-08). The bank account
 * (1010) holds one line: PAY-2026-0001, ₱3,846.08 received on 2026-09-08.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08T10:00:00+08:00'));
    $this->world = World::build();
    $this->repair = $this->world->id('mekanikomor-binan');
});

function clAs(string $email = 'owner@mekanikomore.ph'): void
{
    Sanctum::actingAs(test()->world->user($email));
}

/** @return array<string, array<string, mixed>> the chart by code */
function clChart(string $asOf = '2026-10-31'): array
{
    return collect(test()->getJson("/api/v1/ledger/accounts?as_of={$asOf}")->assertOk()->json('data'))->keyBy('code')->all();
}

/**
 * @param  list<array{string, int, int}>  $lines  account code, debit, credit
 * @param  array<string, mixed>  $extra
 * @return TestResponse<JsonResponse>
 */
function clVoucher(array $lines, array $extra = []): TestResponse
{
    $chart = clChart();

    return test()->postJson('/api/v1/ledger/vouchers', $extra + [
        'branch_id' => test()->repair,
        'memo' => 'Bank charge for September',
        'lines' => array_map(fn (array $line): array => ['account_id' => $chart[$line[0]]['id'], 'debit_cents' => $line[1], 'credit_cents' => $line[2]], $lines),
    ], ['Idempotency-Key' => 'jv-'.bin2hex(random_bytes(6))]);
}

it('posts a journal voucher that balances, refuses one that does not, and reverses it once', function () {
    clAs();
    $voucher = clVoucher([['6010', 1_500, 0], ['1010', 0, 1_500]], ['reference' => 'BDO SOA 09/2026'])->assertCreated()->json('data');
    expect($voucher)->toMatchArray(['status' => 'posted', 'entry_date' => '2026-10-08', 'total_cents' => 1_500, 'reference' => 'BDO SOA 09/2026', 'can_reverse' => true]);

    $entry = $this->getJson("/api/v1/ledger/journal?source_id={$voucher['id']}")->assertOk()->json('data.0');
    expect($entry['event'])->toBe('manual_journal')
        ->and($entry['number'])->toStartWith('JE-2026-')
        ->and(array_map(fn (array $l): array => [$l['account_code'], $l['debit_cents'], $l['credit_cents']], $entry['lines']))->toBe([['6010', 1_500, 0], ['1010', 0, 1_500]])
        ->and(clChart()['1010']['balance_cents'])->toBe(384_608 - 1_500);

    // Debits equal credits; a line is one side; a control account moves only by its own documents; never the future.
    clVoucher([['6010', 1_500, 0], ['1010', 0, 1_400]])->assertStatus(422)->assertJsonPath('error.details.fields.lines.0', fn (string $m): bool => str_contains($m, 'must be equal'));
    clVoucher([['6010', 1_500, 1_500], ['1010', 0, 0]])->assertStatus(422);
    foreach (['1100', '1200', '2000', '2100', '2300', '2400'] as $control) {
        clVoucher([[$control, 1_000, 0], ['1010', 0, 1_000]])->assertStatus(422)->assertJsonPath('error.details.fields', fn (array $f): bool => str_contains(json_encode($f), 'control account'));
    }
    clVoucher([['6010', 1_500, 0], ['1010', 0, 1_500]], ['entry_date' => '2026-10-09'])->assertStatus(422);
    $this->postJson('/api/v1/ledger/vouchers', ['branch_id' => $this->repair, 'memo' => 'No key', 'lines' => []])->assertStatus(422);

    $this->postJson("/api/v1/ledger/vouchers/{$voucher['id']}/reverse", ['reason' => 'Charged to the wrong account'])->assertOk()->assertJsonPath('data.status', 'reversed')->assertJsonPath('data.can_reverse', false);
    $this->postJson("/api/v1/ledger/vouchers/{$voucher['id']}/reverse", ['reason' => 'Again'])->assertStatus(409);
    $entries = collect($this->getJson("/api/v1/ledger/journal?source_id={$voucher['id']}")->json('data'));
    expect($entries->pluck('event')->sort()->values()->all())->toBe(['manual_journal', 'manual_journal_reversed'])
        ->and(clChart()['1010']['balance_cents'])->toBe(384_608)
        ->and(clChart()['6010']['balance_cents'])->toBe(0);

    // A posted voucher is never edited or deleted, even by raw SQL.
    $refused = function (string $sql): string {
        try {
            DB::transaction(fn () => DB::statement($sql));
        } catch (QueryException $e) {
            return (string) $e->getCode();
        }

        return 'accepted';
    };
    expect($refused("update manual_journals set memo = 'x' where id = '{$voucher['id']}'"))->toBe('23001')
        ->and($refused("delete from manual_journals where id = '{$voucher['id']}'"))->toBe('23001');
});

it('keeps vouchers to those who may change the books', function () {
    clAs();
    $voucher = clVoucher([['6010', 1_500, 0], ['1010', 0, 1_500]])->assertCreated()->json('data');

    // A branch manager reads their own branch's vouchers and writes none; nobody else sees any.
    clAs('manager.samahuzai@mekanikomore.ph');
    expect($this->getJson('/api/v1/ledger/vouchers')->assertOk()->json('data'))->toBe([]);
    $this->getJson("/api/v1/ledger/vouchers/{$voucher['id']}")->assertNotFound();
    $chart = clChart();
    $this->postJson('/api/v1/ledger/vouchers', ['memo' => 'Mine', 'lines' => [['account_id' => $chart['6010']['id'], 'debit_cents' => 100], ['account_id' => $chart['1010']['id'], 'credit_cents' => 100]]], ['Idempotency-Key' => 'bm'])->assertForbidden();
    foreach (['cashier@mekanikomore.ph', 'donmiguel@mekanikomor.ph'] as $email) {
        clAs($email);
        $this->getJson('/api/v1/ledger/vouchers')->assertForbidden();
    }
    clAs('owner@mekanikomore.ph');
    $this->getJson('/api/v1/ledger/vouchers/'.$this->world->id('rival:voucher'))->assertNotFound();
});

it('reconciles a bank account: only what the statement shows clears, and only an agreeing statement completes', function () {
    clAs();
    $bank = clChart()['1010']['id'];

    // September's statement closes at ₱3,846.08: the one transfer received.
    $september = $this->postJson('/api/v1/ledger/bank-reconciliations', ['account_id' => $bank, 'statement_date' => '2026-09-30', 'statement_balance_cents' => 384_608])->assertCreated()->json('data');
    expect($september)->toMatchArray(['status' => 'open', 'account_code' => '1010', 'cleared_cents' => 0, 'difference_cents' => 384_608, 'outstanding_cents' => 384_608, 'book_balance_cents' => 384_608, 'agrees' => false])
        ->and($september['lines'])->toHaveCount(1)
        ->and($september['lines'][0])->toMatchArray(['reference' => 'PAY-2026-0001', 'debit_cents' => 384_608, 'cleared' => false, 'entry_date' => '2026-09-08']);
    // One statement at a time per account; nothing completes while the statement disagrees.
    $this->postJson('/api/v1/ledger/bank-reconciliations', ['account_id' => $bank, 'statement_date' => '2026-10-08', 'statement_balance_cents' => 0])->assertStatus(409)->assertJsonPath('error.details.reason', 'reconciliation_open');
    $this->postJson("/api/v1/ledger/bank-reconciliations/{$september['id']}/complete")->assertStatus(409)->assertJsonPath('error.details.reason', 'statement_does_not_agree')->assertJsonPath('error.details.difference_cents', 384_608);

    $line = $september['lines'][0]['journal_line_id'];
    $ticked = $this->putJson("/api/v1/ledger/bank-reconciliations/{$september['id']}/lines", ['clear' => [$line]])->assertOk()->json('data');
    expect($ticked)->toMatchArray(['ticked_cents' => 384_608, 'difference_cents' => 0, 'outstanding_cents' => 0, 'agrees' => true])
        ->and($ticked['lines'][0]['cleared'])->toBeTrue();
    // Unticking and ticking again is allowed while it is open.
    $this->putJson("/api/v1/ledger/bank-reconciliations/{$september['id']}/lines", ['unclear' => [$line]])->assertOk()->assertJsonPath('data.agrees', false);
    $this->putJson("/api/v1/ledger/bank-reconciliations/{$september['id']}/lines", ['clear' => [$line]])->assertOk();
    $this->postJson("/api/v1/ledger/bank-reconciliations/{$september['id']}/complete")->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.completed_by_name', 'Mike Manabat');
    // Completed is final.
    $this->putJson("/api/v1/ledger/bank-reconciliations/{$september['id']}/lines", ['unclear' => [$line]])->assertStatus(409);
    $this->deleteJson("/api/v1/ledger/bank-reconciliations/{$september['id']}")->assertStatus(409);

    // October: the bank took a ₱15.00 charge the books did not know about. It is recorded first, then ticked.
    $this->postJson('/api/v1/ledger/bank-reconciliations', ['account_id' => $bank, 'statement_date' => '2026-09-30', 'statement_balance_cents' => 0])->assertStatus(422);
    clVoucher([['6010', 1_500, 0], ['1010', 0, 1_500]])->assertCreated();
    // A payment to a vendor by cheque that the bank has not seen yet stays outstanding.
    $landlord = $this->postJson('/api/v1/vendors', ['name' => 'Biñan Commercial Leasing'])->assertCreated()->json('data.id');
    $this->postJson('/api/v1/vendor-bills', ['vendor_id' => $landlord, 'branch_id' => $this->repair, 'vendor_invoice_no' => 'R-10', 'lines' => [['kind' => 'expense', 'account_id' => clChart()['6100']['id'], 'description' => 'Rent', 'unit_cost_cents' => 100_000]], 'payment' => ['method' => 'check', 'reference_no' => 'CHK 0001']], ['Idempotency-Key' => 'rent'])->assertCreated();

    $october = $this->postJson('/api/v1/ledger/bank-reconciliations', ['account_id' => $bank, 'statement_date' => '2026-10-08', 'statement_balance_cents' => 384_608 - 1_500])->assertCreated()->json('data');
    expect($october)->toMatchArray(['cleared_before_cents' => 384_608, 'ticked_cents' => 0, 'difference_cents' => -1_500, 'outstanding_cents' => -1_500 - 112_000, 'book_balance_cents' => 384_608 - 1_500 - 112_000])
        ->and($october['lines'])->toHaveCount(2);
    $charge = collect($october['lines'])->firstWhere('credit_cents', 1_500)['journal_line_id'];
    // A line that cleared on an earlier statement does not clear again; nor does a line of another account.
    $this->putJson("/api/v1/ledger/bank-reconciliations/{$october['id']}/lines", ['clear' => [$line]])->assertStatus(422);
    $done = $this->putJson("/api/v1/ledger/bank-reconciliations/{$october['id']}/lines", ['clear' => [$charge]])->assertOk()->json('data');
    expect($done)->toMatchArray(['cleared_cents' => 384_608 - 1_500, 'difference_cents' => 0, 'outstanding_cents' => -112_000, 'agrees' => true]);
    $this->postJson("/api/v1/ledger/bank-reconciliations/{$october['id']}/complete")->assertOk()->assertJsonPath('data.book_balance_cents', 384_608 - 1_500 - 112_000);
    expect($this->getJson('/api/v1/ledger/bank-reconciliations')->assertOk()->json('data.*.statement_date'))->toBe(['2026-10-08', '2026-09-30']);

    // Not a bank: a control account is not reconciled this way. An open one may be discarded.
    $this->postJson('/api/v1/ledger/bank-reconciliations', ['account_id' => clChart()['1100']['id'], 'statement_date' => '2026-10-08', 'statement_balance_cents' => 0])->assertStatus(422);
    $cash = $this->postJson('/api/v1/ledger/bank-reconciliations', ['account_id' => clChart()['1000']['id'], 'statement_date' => '2026-10-08', 'statement_balance_cents' => 0])->assertCreated()->json('data.id');
    $this->deleteJson("/api/v1/ledger/bank-reconciliations/{$cash}")->assertNoContent();
    $this->getJson("/api/v1/ledger/bank-reconciliations/{$cash}")->assertNotFound();

    // Whole-organization: a branch-limited sign-in cannot reconcile, nor can anyone without the books.
    clAs('manager.samahuzai@mekanikomore.ph');
    $this->getJson('/api/v1/ledger/bank-reconciliations')->assertForbidden();
    $this->getJson("/api/v1/ledger/bank-reconciliations/{$october['id']}")->assertForbidden();
    clAs('cashier@mekanikomore.ph');
    $this->getJson('/api/v1/ledger/bank-reconciliations')->assertForbidden();
    clAs('owner@mekanikomore.ph');
    $this->getJson('/api/v1/ledger/bank-reconciliations/'.$this->world->id('rival:bank-reconciliation'))->assertNotFound();
});

it('closes the year: profit moves to Retained Earnings, December closes, and the year still reads as it was earned', function () {
    clAs();
    // Not before the year is over.
    $this->postJson('/api/v1/ledger/years/close', ['year' => 2026])->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
    expect($this->getJson('/api/v1/ledger/years?year=2026')->assertOk()->json('data.0'))->toMatchArray(['status' => 'open', 'ended' => false, 'can_close' => false, 'blocked_by' => '2026 is not over yet.']);

    $this->travelTo(CarbonImmutable::parse('2027-01-05T09:00:00+08:00'));
    $before = clChart('2026-12-31');
    $profitBefore = $this->getJson('/api/v1/ledger/reports/profit-and-loss?from=2026-01-01&to=2026-12-31')->assertOk()->json('data');
    $year = $this->getJson('/api/v1/ledger/years?year=2026')->assertOk()->json('data.0');
    expect($year['net_income_cents'])->toBe($year['revenue_cents'] - $year['expense_cents'])
        ->and($year['net_income_cents'])->toBeGreaterThan(0)
        // December closes with the year, so the months before it come first.
        ->and($year['can_close'])->toBeFalse()
        ->and($year['blocked_by'])->toContain('July 2026');
    $this->postJson('/api/v1/ledger/years/close', ['year' => 2026])->assertStatus(409);
    expect(asSystem(fn () => DB::table('fiscal_years')->count()))->toBe(0);

    foreach (['2026-07', '2026-08', '2026-09', '2026-10', '2026-11'] as $month) {
        $this->postJson('/api/v1/ledger/periods/close', ['period' => $month])->assertOk();
    }
    expect($this->getJson('/api/v1/ledger/years?year=2026')->json('data.0.can_close'))->toBeTrue();

    $closed = $this->postJson('/api/v1/ledger/years/close', ['year' => 2026])->assertOk()->json('data');
    expect($closed)->toMatchArray(['status' => 'closed', 'net_income_cents' => $year['net_income_cents'], 'december_status' => 'closed', 'can_close' => false, 'closed_by_name' => 'Mike Manabat']);

    // One entry, dated 31 December: every revenue and expense account is empty, Retained Earnings holds the profit.
    $entry = $this->getJson('/api/v1/ledger/journal?event=year_closed')->assertOk()->json('data');
    expect($entry)->toHaveCount(1)
        ->and($entry[0]['entry_date'])->toBe('2026-12-31')
        ->and($entry[0]['reference'])->toBe('FY2026');
    $after = clChart('2026-12-31');
    foreach ($after as $code => $account) {
        if (in_array($account['type'], ['revenue', 'expense'], true)) {
            expect($account['balance_cents'])->toBe(0, "{$code} should be closed");
        } elseif ((string) $code !== '3100') {
            expect($account['balance_cents'])->toBe($before[$code]['balance_cents'], "{$code} is a balance-sheet account; the close leaves it alone");
        }
    }
    expect($after['3100']['balance_cents'])->toBe($year['net_income_cents']);

    // The balance sheet balances with nothing left in current earnings; the year's P&L still shows what was earned.
    $sheet = $this->getJson('/api/v1/ledger/reports/balance-sheet?as_of=2026-12-31')->assertOk()->json('data');
    expect($sheet['balanced'])->toBeTrue()->and($sheet['current_earnings_cents'])->toBe(0);
    $profitAfter = $this->getJson('/api/v1/ledger/reports/profit-and-loss?from=2026-01-01&to=2026-12-31')->assertOk()->json('data');
    expect($profitAfter['net_profit'])->toBe($profitBefore['net_profit']);
    $trial = $this->getJson('/api/v1/ledger/reports/trial-balance?as_of=2026-12-31')->assertOk()->json('data');
    expect($trial['balanced'])->toBeTrue();

    // Final: December takes nothing more, the year closes once, and 2027 is not over.
    expect(collect($this->getJson('/api/v1/ledger/periods')->json('data'))->firstWhere('period_key', '2026-12')['status'])->toBe('closed');
    $this->postJson('/api/v1/ledger/years/close', ['year' => 2026])->assertStatus(409);
    $this->postJson('/api/v1/ledger/years/close', ['year' => 2027])->assertStatus(409);
    expect(fn () => DB::transaction(fn () => DB::statement('update fiscal_years set net_income_cents = 0')))->toThrow(QueryException::class);
    expect(collect($this->getJson('/api/v1/ledger/years')->assertOk()->json('data'))->pluck('status', 'year')->all())->toBe([2027 => 'open', 2026 => 'closed']);

    // New work lands in the new year and its profit starts from nothing.
    $draft = $this->world->id('invoice:sagrada-draft');
    $this->postJson("/api/v1/invoices/{$draft}/issue", [], ['Idempotency-Key' => 'fy-new'])->assertOk();
    $next = $this->getJson('/api/v1/ledger/years?year=2027')->json('data.0');
    expect($next['revenue_cents'])->toBeGreaterThan(0)
        ->and(asSystem(fn () => JournalEntry::query()->where('organization_id', $this->world->id('prov-mekanikomore'))->where('event', 'year_closed')->count()))->toBe(1);
});

it('gives a chart installed before this phase the accounts its new rules need, without touching a code already taken', function () {
    $organizationId = $this->world->id('prov-mekanikomore');
    // The books as they were before: no Phase 12 rules or accounts, and the organization's own account on 3100.
    asSystem(function () use ($organizationId): void {
        DB::statement('set local session_replication_role = replica');
        DB::table('posting_rules')->where('organization_id', $organizationId)->whereIn('rule_key', ['sales.returns', 'tax.creditable_withholding', 'tax.withholding_payable', 'equity.retained_earnings'])->delete();
        DB::table('accounts')->where('organization_id', $organizationId)->whereIn('code', ['4910', '1310', '2210', '3100'])->delete();
        DB::statement('set local session_replication_role = origin');
        DB::table('accounts')->insert([
            'id' => strtolower((string) Str::ulid()), 'organization_id' => $organizationId, 'code' => '3100', 'name' => 'Owner\'s Drawings',
            'type' => 'equity', 'normal_side' => 'debit', 'position' => 999, 'created_at' => now(), 'updated_at' => now(),
        ]);
    });
    clAs();

    // The next posting that needs a new rule installs what is missing.
    $this->postJson('/api/v1/invoices/'.$this->world->id('invoice:actimed-current').'/credit-notes', ['reason' => 'Goodwill', 'lines' => [['description' => 'Allowance', 'unit_price_cents' => 1_000]]], ['Idempotency-Key' => 'upgrade'])->assertCreated();

    $chart = clChart();
    $rules = collect($this->getJson('/api/v1/ledger/posting-rules')->assertOk()->json('data'))->keyBy('key');
    expect($chart['4910']['name'])->toBe('Sales Returns & Allowances')
        ->and($chart['4910']['balance_cents'])->toBe(1_000)
        ->and($chart['1310']['name'])->toBe('Creditable Withholding Tax')
        ->and($chart['2210']['name'])->toBe('Withholding Tax Payable')
        // 3100 was the organization's own: it is left alone, and Retained Earnings takes the next free code.
        ->and($chart['3100']['name'])->toBe('Owner\'s Drawings')
        ->and($chart['3101']['name'])->toBe('Retained Earnings')
        ->and($rules['equity.retained_earnings']['account_id'])->toBe($chart['3101']['id'])
        ->and($rules['sales.returns']['account_id'])->toBe($chart['4910']['id']);
});

it('lets only the organization admin, signed in to every branch, close a year', function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-05T09:00:00+08:00'));
    // A year's profit is the whole organization's: a branch-limited manager neither reads nor closes it.
    clAs('manager.samahuzai@mekanikomore.ph');
    $this->getJson('/api/v1/ledger/years')->assertForbidden();
    $this->postJson('/api/v1/ledger/years/close', ['year' => 2026])->assertForbidden();
    foreach (['cashier@mekanikomore.ph', 'donmiguel@mekanikomor.ph'] as $email) {
        clAs($email);
        $this->getJson('/api/v1/ledger/years')->assertForbidden();
        $this->postJson('/api/v1/ledger/years/close', ['year' => 2026])->assertForbidden();
    }
});

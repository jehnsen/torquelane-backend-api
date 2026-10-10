<?php

declare(strict_types=1);

use App\Actions\Inventory\PostStockMove;
use App\Actions\Ledger\BackfillLedger;
use App\Actions\Ledger\PostingRules;
use App\Domain\Inventory\MoveRequest;
use App\Domain\Inventory\MoveType;
use App\Domain\Inventory\StockSource;
use App\Domain\Ledger\ChartOfAccounts;
use App\Domain\Ledger\RuleKey;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Organization;
use App\Models\StockLocation;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\World;

/*
 * The general ledger (Phase 8) over the demo seed (today 2026-10-08):
 * INV-2026-0001 (Actimed, 07-25), INV-2026-0002 (Northwind, 08-19, unpaid),
 * INV-2026-0003 (Actimed, 09-18), PAY-2026-0001 (09-08, part of 0001), and the
 * stock room's opening balances, receipt, transfer and count (all 10-08).
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08T10:00:00+08:00'));
    $this->world = World::build();
    $this->actimed = $this->world->id('fc-actimed');
});

function lgSignIn(string $email = 'owner@mekanikomore.ph'): void
{
    Sanctum::actingAs(test()->world->user($email));
}

/** @return array<string, mixed> */
function lgChecklist(string $period = '2026-10'): array
{
    return test()->getJson("/api/v1/ledger/periods/checklist?period={$period}")->assertOk()->json('data');
}

/** @return array<string, bool> check key => passed */
function lgChecks(string $period = '2026-10'): array
{
    $out = [];
    foreach (lgChecklist($period)['checklist'] as $check) {
        $out[$check['key']] = $check['passed'];
    }

    return $out;
}

function lgAccountBalance(string $code, string $asOf = '2026-10-31'): int
{
    $row = collect(test()->getJson("/api/v1/ledger/accounts?as_of={$asOf}")->json('data'))->firstWhere('code', $code);

    return $row['balance_cents'];
}

/** Delete every journal row, the way only a superuser could: to prove the backfill rebuilds it. */
function lgWipeJournal(): void
{
    DB::statement('set local session_replication_role = replica');
    DB::statement('delete from journal_lines');
    DB::statement('delete from journal_entries');
    DB::statement('set local session_replication_role = origin');
}

function lgOrg(): Organization
{
    return Organization::query()->orderBy('id')->firstOrFail();
}

/** The demo organization's journal entries (the world also holds a rival organization's). */
function lgEntries(): int
{
    return JournalEntry::query()->withoutGlobalScopes()->where('organization_id', lgOrg()->id)->count();
}

function lgBackfill(bool $dry = false): array
{
    return asSystem(fn () => app(BackfillLedger::class)->organization(lgOrg(), $dry));
}

/** @return array<string, array<string, mixed>> account code => trial balance row */
function lgTrialRows(string $asOf = '2026-10-31'): array
{
    $tb = test()->getJson("/api/v1/ledger/reports/trial-balance?as_of={$asOf}")->assertOk()->json('data');

    return collect($tb['accounts'])->keyBy('code')->all();
}

// ------------------------------------------------------------------ seeded books

it('balances on the seeded activity, and every control account reconciles', function () {
    lgSignIn();

    $tb = $this->getJson('/api/v1/ledger/reports/trial-balance')->assertOk()->json('data');
    expect($tb['balanced'])->toBeTrue()
        ->and($tb['total_debit_cents'])->toBe($tb['total_credit_cents'])
        ->and($tb['total_debit_cents'])->toBeGreaterThan(0);

    $checklist = lgChecklist();
    foreach ($checklist['checklist'] as $check) {
        expect($check['passed'])->toBeTrue("{$check['key']}: {$check['detail']}");
    }
    expect(lgChecks())->toBe(['unposted_sources' => true, 'receivables' => true, 'inventory' => true, 'customer_deposits' => true, 'trial_balance' => true]);

    // Receivables: Actimed's 0001 less its part payment, 0003, and Northwind's 0002.
    expect(lgAccountBalance('1100'))->toBe((961_520 - 384_608) + 208_880 + 527_520);
    // Output VAT is 12% of the sales the three invoices booked.
    $rows = lgTrialRows();
    expect($rows['2200']['credit_cents'])->toBe(intdiv(($rows['4000']['credit_cents'] + $rows['4010']['credit_cents']) * 12, 100));
    // The part payment went to the bank, none of it is still a deposit.
    expect($rows['1010']['debit_cents'])->toBe(384_608);
});

it('keeps the balance sheet in balance with the earnings to date', function () {
    lgSignIn();

    $sheet = $this->getJson('/api/v1/ledger/reports/balance-sheet?as_of=2026-10-08')->assertOk()->json('data');
    expect($sheet['balanced'])->toBeTrue()
        ->and($sheet['total_assets_cents'])->toBe($sheet['total_liabilities_cents'] + $sheet['total_equity_cents'])
        ->and($sheet['current_earnings_cents'])->toBe(lgTrialRows()['4000']['credit_cents'] + lgTrialRows()['4010']['credit_cents'] - lgTrialRows()['5100']['debit_cents']);
});

it('reports profit and loss by branch and consolidated, and they agree', function () {
    lgSignIn();

    $pl = $this->getJson('/api/v1/ledger/reports/profit-and-loss?from=2026-07-01&to=2026-10-31')->assertOk()->json('data');
    $repair = $this->world->id('mekanikomor-binan');
    $detailing = $this->world->id('samahuzai-binan');

    expect($pl['branches'])->toHaveCount(2)
        ->and($pl['net_profit']['total_cents'])->toBe(array_sum($pl['net_profit']['by_branch']))
        ->and($pl['gross_profit']['by_branch'][$detailing])->toBe(0)
        ->and($pl['revenue'][0]['by_branch'][$repair])->toBeGreaterThan(0);
    $sales = collect($pl['revenue'])->sum('total_cents');
    expect($pl['gross_profit']['total_cents'])->toBe($sales);
});

it('lists a general ledger with a running balance that ends on the account balance', function () {
    lgSignIn();
    $ar = collect($this->getJson('/api/v1/ledger/accounts')->json('data'))->firstWhere('code', '1100');

    $gl = $this->getJson("/api/v1/ledger/reports/general-ledger/{$ar['id']}?from=2026-07-01&to=2026-10-31")->assertOk()->json('data');
    $lines = $gl['lines'];

    expect($gl['opening_balance_cents'])->toBe(0)
        ->and($gl['closing_balance_cents'])->toBe($ar['balance_cents'])
        ->and(end($lines)['balance_cents'])->toBe($ar['balance_cents'])
        ->and($gl['total_debit_cents'] - $gl['total_credit_cents'])->toBe($ar['balance_cents']);

    // A range that starts later opens with what came before.
    $later = $this->getJson("/api/v1/ledger/reports/general-ledger/{$ar['id']}?from=2026-09-01&to=2026-10-31")->json('data');
    expect($later['opening_balance_cents'])->toBeGreaterThan(0)
        ->and($later['closing_balance_cents'])->toBe($ar['balance_cents']);
});

it('splits the day\'s sales by branch and the money received by method', function () {
    lgSignIn();

    $report = $this->getJson('/api/v1/ledger/reports/daily-sales?from=2026-09-01&to=2026-09-30')->assertOk()->json('data');
    $days = collect($report['days'])->keyBy('date');

    // 09-08: PAY-2026-0001 by bank transfer; 09-18: INV-2026-0003 issued.
    expect($days['2026-09-08']['received_cents'])->toBe(384_608)
        ->and($days['2026-09-08']['receipts'])->toBe([['method' => 'bank_transfer', 'received_cents' => 384_608]])
        ->and($days['2026-09-18']['invoiced_cents'])->toBe(208_880)
        ->and($days['2026-09-18']['net_sales_cents'] + $days['2026-09-18']['vat_cents'])->toBe(208_880)
        ->and($report['totals']['received_cents'])->toBe(384_608);
});

// ----------------------------------------------------------------- the postings

it('books an invoice in the same transaction as issuing it, and numbers the entry', function () {
    lgSignIn('cashier@mekanikomore.ph');
    $draft = $this->world->id('invoice:sagrada-draft');
    $before = lgEntries();

    $issued = $this->postJson("/api/v1/invoices/{$draft}/issue", [], ['Idempotency-Key' => 'lg-issue-1'])->assertOk()->json('data');

    lgSignIn();
    $entries = $this->getJson("/api/v1/ledger/journal?source_id={$draft}")->assertOk()->json('data');
    expect($entries)->toHaveCount(1)
        ->and($entries[0]['event'])->toBe('invoice_issued')
        ->and($entries[0]['reference'])->toBe($issued['number'])
        ->and($entries[0]['number'])->toStartWith('JE-2026-')
        ->and($entries[0]['entry_date'])->toBe('2026-10-08')
        ->and($entries[0]['total_cents'])->toBeGreaterThanOrEqual($issued['totals']['total_due_cents'])
        ->and(lgEntries())->toBe($before + 1);

    $receivable = collect($entries[0]['lines'])->firstWhere('account_code', '1100');
    expect($receivable['debit_cents'])->toBe($issued['totals']['total_due_cents'])
        ->and($receivable['customer_account_id'])->not->toBeNull();
    $vat = collect($entries[0]['lines'])->firstWhere('account_code', '2200');
    expect($vat['credit_cents'])->toBe($issued['totals']['vat_amount_cents']);
    expect(lgChecks())->each->toBeTrue();
});

it('numbers journal entries gap-free in the organization\'s series', function () {
    lgSignIn();

    $numbers = collect($this->getJson('/api/v1/ledger/journal?per_page=100')->json('data'))->pluck('number')->sort()->values();
    $total = $this->getJson('/api/v1/ledger/journal?per_page=1')->json('meta.total');

    expect($numbers->first())->toBe('JE-2026-0001')
        ->and($numbers->count())->toBe(min(100, $total));
    foreach ($numbers as $i => $number) {
        expect($number)->toBe(sprintf('JE-2026-%04d', $i + 1));
    }
});

it('reverses a voided invoice in the current period, dated today, leaving the original alone', function () {
    lgSignIn();
    foreach (['2026-07', '2026-08', '2026-09'] as $month) {
        $this->postJson('/api/v1/ledger/periods/close', ['period' => $month])->assertOk();
    }
    $northwind = $this->world->id('invoice:northwind');

    // INV-2026-0002 sits in August, which is closed.
    $this->postJson("/api/v1/invoices/{$northwind}/void", ['reason' => 'Billed to the wrong depot'])->assertOk();

    $entries = collect($this->getJson("/api/v1/ledger/journal?source_id={$northwind}")->json('data'))->keyBy('event');
    $original = $entries['invoice_issued'];
    $reversal = $entries['invoice_voided'];

    expect($original['entry_date'])->toBe('2026-08-19')
        ->and($original['period'])->toBe('2026-08')
        ->and($original['period_closed'])->toBeTrue()
        ->and($reversal['entry_date'])->toBe('2026-10-08')
        ->and($reversal['period'])->toBe('2026-10')
        ->and($reversal['reversal_of_id'])->toBe($original['id'])
        ->and($reversal['reference'])->toBe('INV-2026-0002')
        ->and($reversal['memo'])->toContain($original['number'])->toContain('closed')
        ->and($reversal['total_cents'])->toBe($original['total_cents']);
    // Mirror image, line for line.
    foreach ($original['lines'] as $i => $line) {
        expect($reversal['lines'][$i]['account_id'])->toBe($line['account_id'])
            ->and($reversal['lines'][$i]['debit_cents'])->toBe($line['credit_cents'])
            ->and($reversal['lines'][$i]['credit_cents'])->toBe($line['debit_cents']);
    }
    // The August books did not move; the receivable is released.
    expect(lgAccountBalance('1100', '2026-08-31'))->toBe(961_520 + 527_520)
        ->and(lgAccountBalance('1100', '2026-10-31'))->toBe((961_520 - 384_608) + 208_880);
    expect(lgChecks())->each->toBeTrue();
});

it('records a payment as a deposit and applies it, and a void undoes both', function () {
    lgSignIn('cashier@mekanikomore.ph');
    $payment = $this->postJson('/api/v1/payments', [
        'customer_account_id' => $this->actimed,
        'method' => 'gcash',
        'reference_no' => 'GC-778812',
        'amount_cents' => 300_000,
    ], ['Idempotency-Key' => 'lg-pay-1'])->assertCreated()->json('data');

    lgSignIn();
    $entries = collect($this->getJson("/api/v1/ledger/journal?q={$payment['number']}")->json('data'));
    $received = $entries->firstWhere('event', 'payment_received');
    expect($received['payment_method'])->toBe('gcash')
        ->and(collect($received['lines'])->firstWhere('account_code', '1020')['debit_cents'])->toBe(300_000)
        ->and(collect($received['lines'])->firstWhere('account_code', '2300')['credit_cents'])->toBe(300_000)
        ->and($entries->where('event', 'credit_applied')->count())->toBe(count($payment['allocations']))
        ->and(lgChecks())->each->toBeTrue();

    $before = lgAccountBalance('1100');
    $this->postJson("/api/v1/payments/{$payment['id']}/void", ['reason' => 'Bounced'])->assertOk();

    $after = collect($this->getJson("/api/v1/ledger/journal?q={$payment['number']}")->json('data'));
    expect($after->where('event', 'payment_voided')->count())->toBe(1)
        ->and($after->where('event', 'credit_reversed')->count())->toBe(count($payment['allocations']))
        // The receivable is owed again, the deposit and the clearing account are empty.
        ->and(lgAccountBalance('1100'))->toBe($before + 300_000)
        ->and(lgAccountBalance('1020'))->toBe(0)
        ->and(lgAccountBalance('2300'))->toBe(0)
        ->and(lgChecks())->each->toBeTrue();
});

it('holds unapplied credit as a customer deposit until it is applied', function () {
    lgSignIn('cashier@mekanikomore.ph');
    // Northwind owes ₱5,275.20; pay ₱6,000.00 against nothing in particular, then check the leftover.
    $this->postJson('/api/v1/payments', [
        'customer_account_id' => $this->world->id('fc-northwind'),
        'method' => 'cash',
        'amount_cents' => 600_000,
    ], ['Idempotency-Key' => 'lg-pay-2'])->assertCreated()->assertJsonPath('data.unallocated_cents', 600_000 - 527_520);

    lgSignIn();
    expect(lgAccountBalance('2300'))->toBe(600_000 - 527_520)
        ->and(lgAccountBalance('1000'))->toBe(600_000)
        ->and(lgChecks()['customer_deposits'])->toBeTrue();
});

it('posts goods received to Inventory against GR/IR, and a void clears it again', function () {
    lgSignIn();
    $grir = lgAccountBalance('2100');
    $inventory = lgAccountBalance('1200');
    $receipt = $this->world->id('goods-receipt:top-up');
    $value = collect($this->getJson("/api/v1/goods-receipts/{$receipt}")->json('data.lines'))->sum('line_total_cents');

    expect($grir)->toBe($value);

    $this->postJson("/api/v1/goods-receipts/{$receipt}/void", ['reason' => 'Wrong delivery'])->assertOk();

    expect(lgAccountBalance('2100'))->toBe(0)
        ->and(lgAccountBalance('1200'))->toBeLessThan($inventory)
        ->and(lgChecks()['inventory'])->toBeTrue()
        ->and(lgChecks()['trial_balance'])->toBeTrue();
});

it('issues parts to the cost of sales at the average, and the Inventory account follows the stock room', function () {
    lgSignIn();
    $before = lgAccountBalance('1200');
    $repairStore = StockLocation::query()->withoutGlobalScopes()->findOrFail($this->world->id('location:mekanikomor-binan'));

    asSystem(fn () => DB::transaction(fn () => app(PostStockMove::class)->handle($repairStore, $this->world->id('item:90915-YZZD4'), new MoveRequest(MoveType::Issue, '-2'), StockSource::Manual, null, 'Workshop use')));

    $rows = lgTrialRows();
    // 2 oil filters at the repair store's ₱352.31 average.
    expect($rows['5000']['debit_cents'])->toBe(70_462)
        ->and(lgAccountBalance('1200'))->toBe($before - 70_462)
        ->and(lgChecks()['inventory'])->toBeTrue();
});

it('posts a count variance to Inventory Adjustments', function () {
    lgSignIn();

    // SC-2026-0001 found a litre of coolant (₱520) short.
    $entry = collect($this->getJson('/api/v1/ledger/journal?event=stock_adjustment')->json('data'))->first();
    expect($entry['reference'])->toBe('SC-2026-0001')
        ->and(collect($entry['lines'])->firstWhere('account_code', '5100')['debit_cents'])->toBe(52_000)
        ->and(collect($entry['lines'])->firstWhere('account_code', '1200')['credit_cents'])->toBe(52_000);
});

it('moves stock between branches with no profit or loss', function () {
    lgSignIn();
    $period = '?from=2026-10-01&to=2026-10-31';
    $profitBefore = $this->getJson("/api/v1/ledger/reports/profit-and-loss{$period}")->json('data.net_profit.total_cents');

    $this->postJson('/api/v1/stock-transfers', [
        'from_location_id' => $this->world->id('location:mekanikomor-binan'),
        'to_location_id' => $this->world->id('location:samahuzai-binan'),
        'lines' => [['item_id' => $this->world->id('item:90915-YZZD4'), 'quantity' => '3']],
    ], ['Idempotency-Key' => 'lg-tr-1'])->assertCreated();

    $entry = collect($this->getJson('/api/v1/ledger/journal?event=stock_transfer')->json('data'))->first();
    $byBranch = [];
    foreach ($entry['lines'] as $line) {
        expect($line['account_code'])->toBe('1200');
        $byBranch[$line['branch_id']] = ($byBranch[$line['branch_id']] ?? 0) + $line['debit_cents'] - $line['credit_cents'];
    }

    expect($byBranch[$this->world->id('samahuzai-binan')])->toBe(-$byBranch[$this->world->id('mekanikomor-binan')])
        ->and($byBranch[$this->world->id('samahuzai-binan')])->toBeGreaterThan(0)
        ->and($entry['counter_branch_id'])->toBe($this->world->id('samahuzai-binan'))
        ->and($this->getJson("/api/v1/ledger/reports/profit-and-loss{$period}")->json('data.net_profit.total_cents'))->toBe($profitBefore)
        ->and(lgChecks())->each->toBeTrue();
});

// -------------------------------------------------------------------- periods

it('closes months in order, only once they are over and every check passes', function () {
    lgSignIn();

    $this->postJson('/api/v1/ledger/periods/close', ['period' => '2026-10'])->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
    $this->postJson('/api/v1/ledger/periods/close', ['period' => '2026-09'])->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
    expect(lgChecklist('2026-09')['blocked_by'])->toContain('July 2026');

    foreach (['2026-07', '2026-08', '2026-09'] as $month) {
        $this->postJson('/api/v1/ledger/periods/close', ['period' => $month])->assertOk()->assertJsonPath('data.status', 'closed');
    }
    $periods = collect($this->getJson('/api/v1/ledger/periods')->json('data'))->keyBy('period_key');
    expect($periods['2026-09']['status'])->toBe('closed')
        ->and($periods['2026-09']['closed_by_name'])->not->toBeNull()
        ->and($periods['2026-10']['status'])->toBe('open');
    $this->postJson('/api/v1/ledger/periods/close', ['period' => '2026-09'])->assertStatus(409);
});

it('rejects posting into a closed period and rolls the whole document back', function () {
    lgSignIn();
    foreach (['2026-07', '2026-08', '2026-09'] as $month) {
        $this->postJson('/api/v1/ledger/periods/close', ['period' => $month])->assertOk();
    }
    $draft = $this->world->id('invoice:sagrada-draft');

    $this->postJson("/api/v1/invoices/{$draft}/issue", ['issue_date' => '2026-09-20'], ['Idempotency-Key' => 'lg-closed-1'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'conflict')
        ->assertJsonPath('error.details.reason', 'period_closed');

    // Still a draft, with no number consumed: the next invoice is 0004.
    $this->getJson("/api/v1/invoices/{$draft}")->assertJsonPath('data.status', 'draft')->assertJsonPath('data.number', null);
    $this->postJson("/api/v1/invoices/{$draft}/issue", [], ['Idempotency-Key' => 'lg-closed-2'])->assertOk()->assertJsonPath('data.number', 'INV-2026-0004');

    // A payment dated into a closed month is refused the same way.
    $this->postJson('/api/v1/payments', ['customer_account_id' => $this->actimed, 'method' => 'cash', 'amount_cents' => 1000, 'received_on' => '2026-09-25', 'branch_id' => $this->world->id('mekanikomor-binan')], ['Idempotency-Key' => 'lg-closed-3'])
        ->assertStatus(409)->assertJsonPath('error.details.reason', 'period_closed');
    expect(lgChecks())->each->toBeTrue();
});

it('refuses to close a month whose books disagree with their source documents, until they are fixed', function () {
    lgSignIn();
    // The books lose INV-2026-0001's entry (only a superuser can do this): the checks must see it.
    $invoice = $this->world->id('invoice:actimed-overdue');
    DB::statement('set local session_replication_role = replica');
    DB::statement('delete from journal_lines where journal_entry_id in (select id from journal_entries where source_id = ?)', [$invoice]);
    DB::statement('delete from journal_entries where source_id = ?', [$invoice]);
    DB::statement('set local session_replication_role = origin');

    $checks = lgChecks('2026-07');
    expect($checks['unposted_sources'])->toBeFalse()
        ->and($checks['receivables'])->toBeFalse();
    $this->postJson('/api/v1/ledger/periods/close', ['period' => '2026-07'])
        ->assertStatus(409)
        ->assertJsonPath('error.details.reason', 'close_checklist_failed');
    expect(collect($this->getJson('/api/v1/ledger/periods')->json('data'))->firstWhere('period_key', '2026-07')['status'])->toBe('open');

    expect(lgBackfill()['posted'])->toBe(['invoices' => 1]);
    $this->postJson('/api/v1/ledger/periods/close', ['period' => '2026-07'])->assertOk();
});

// ------------------------------------------------------------------- backfill

it('rebuilds the whole journal from the source documents, and a second run posts nothing', function () {
    lgSignIn();
    $before = lgEntries();
    $trial = lgTrialRows();
    lgWipeJournal();
    expect(lgEntries())->toBe(0);

    $dry = lgBackfill(dry: true);
    expect($dry['posted'])->not->toBe([])
        ->and(lgEntries())->toBe(0);

    $result = lgBackfill();
    expect($result['skipped_closed'])->toBe(0)
        ->and(lgEntries())->toBe($before)
        ->and(lgTrialRows())->toEqual($trial)
        ->and(lgChecks())->each->toBeTrue();

    expect(lgBackfill()['posted'])->toBe([])
        ->and(lgEntries())->toBe($before);

    // Backfilled entries say so.
    expect(JournalEntry::query()->withoutGlobalScopes()->where('posted_by_name', 'Backfill')->count())->toBe($before);
});

it('backfills voids on the day they happened', function () {
    lgSignIn();
    $northwind = $this->world->id('invoice:northwind');
    $this->travelTo(CarbonImmutable::parse('2026-10-08T15:00:00+08:00'));
    $this->postJson("/api/v1/invoices/{$northwind}/void", ['reason' => 'Duplicate'])->assertOk();
    lgWipeJournal();

    lgBackfill();

    $void = collect($this->getJson("/api/v1/ledger/journal?source_id={$northwind}")->json('data'))->firstWhere('event', 'invoice_voided');
    expect($void['entry_date'])->toBe('2026-10-08')
        ->and(lgChecks())->each->toBeTrue();
});

// --------------------------------------------------------------- posting rules

it('lets an organization admin re-point a rule for postings from then on only', function () {
    lgSignIn();
    $rules = collect($this->getJson('/api/v1/ledger/posting-rules')->assertOk()->json('data'))->keyBy('key');
    expect($rules['sales.parts']['account_code'])->toBe('4010')->and($rules)->toHaveCount(count(RuleKey::cases()));

    $new = $this->postJson('/api/v1/ledger/accounts', ['code' => '4015', 'name' => 'Sales – Accessories', 'type' => 'revenue'], ['Idempotency-Key' => 'lg-acct-1'])->assertCreated()->json('data');
    $this->putJson('/api/v1/ledger/posting-rules', ['rules' => [['key' => 'sales.parts', 'account_id' => $new['id']]]])->assertNoContent();

    $draft = $this->world->id('invoice:sagrada-draft');
    $this->postJson("/api/v1/invoices/{$draft}/issue", [], ['Idempotency-Key' => 'lg-rule-1'])->assertOk();

    $rows = lgTrialRows();
    // The earlier parts sales stay where they were; the new invoice's parts sales went to 4015.
    expect($rows['4010']['credit_cents'])->toBe(1_146_000)
        ->and($rows['4015']['credit_cents'])->toBeGreaterThan(0)
        ->and(lgChecks())->each->toBeTrue();
});

it('refuses a rule pointed at the wrong kind of account, or an inactive one', function () {
    lgSignIn();
    $chart = collect($this->getJson('/api/v1/ledger/accounts')->json('data'))->keyBy('code');

    $this->putJson('/api/v1/ledger/posting-rules', ['rules' => [['key' => 'sales.parts', 'account_id' => $chart['1200']['id']]]])
        ->assertUnprocessable()->assertJsonPath('error.code', 'validation')->assertJsonStructure(['error' => ['details' => ['fields' => ['rules.0.account_id']]]]);
    $this->putJson('/api/v1/ledger/posting-rules', ['rules' => [['key' => 'nonsense', 'account_id' => $chart['4010']['id']]]])->assertUnprocessable();

    $spare = $this->postJson('/api/v1/ledger/accounts', ['code' => '4016', 'name' => 'Spare', 'type' => 'revenue'], ['Idempotency-Key' => 'lg-acct-2'])->assertCreated()->json('data');
    $this->patchJson("/api/v1/ledger/accounts/{$spare['id']}", ['is_active' => false])->assertOk();
    $this->putJson('/api/v1/ledger/posting-rules', ['rules' => [['key' => 'sales.parts', 'account_id' => $spare['id']]]])->assertUnprocessable();
});

it('keeps an account\'s code, type and side once it has been posted to, and a rule\'s account active', function () {
    lgSignIn();
    $chart = collect($this->getJson('/api/v1/ledger/accounts')->json('data'))->keyBy('code');

    $this->patchJson("/api/v1/ledger/accounts/{$chart['4010']['id']}", ['code' => '4011'])->assertStatus(409)->assertJsonPath('error.code', 'conflict');
    $this->patchJson("/api/v1/ledger/accounts/{$chart['4010']['id']}", ['name' => 'Parts sales'])->assertOk()->assertJsonPath('data.name', 'Parts sales');
    $this->patchJson("/api/v1/ledger/accounts/{$chart['4010']['id']}", ['is_active' => false])->assertStatus(409);
    $this->postJson('/api/v1/ledger/accounts', ['code' => '4010', 'name' => 'Duplicate', 'type' => 'revenue'], ['Idempotency-Key' => 'lg-acct-3'])->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['fields' => ['code']]]]);
});

it('is the organization admin\'s to change; a branch manager reads; others are refused', function () {
    lgSignIn('manager.samahuzai@mekanikomore.ph');
    $this->getJson('/api/v1/ledger/accounts')->assertOk();
    $this->getJson('/api/v1/ledger/reports/trial-balance')->assertOk();
    $this->postJson('/api/v1/ledger/accounts', ['code' => '4017', 'name' => 'Extra sales', 'type' => 'revenue'], ['Idempotency-Key' => 'lg-m-1'])->assertForbidden();
    $this->putJson('/api/v1/ledger/posting-rules', ['rules' => [['key' => 'sales.parts', 'account_id' => strtolower((string) Str::ulid())]]])->assertForbidden();
    $this->putJson('/api/v1/ledger/settings', ['accounting_target' => 'xero'])->assertForbidden();
    $this->postJson('/api/v1/ledger/periods/close', ['period' => '2026-07'])->assertForbidden();

    foreach (['cashier@mekanikomore.ph', 'advisor@mekanikomore.ph'] as $email) {
        lgSignIn($email);
        $this->getJson('/api/v1/ledger/accounts')->assertForbidden();
        $this->getJson('/api/v1/ledger/journal')->assertForbidden();
    }
});

it('keeps the books from portal users', function () {
    lgSignIn('fleet@northwind.ph');

    foreach (['accounts', 'posting-rules', 'journal', 'periods', 'reports/trial-balance', 'reports/balance-sheet', 'reports/daily-sales', 'reports/profit-and-loss'] as $path) {
        $this->getJson("/api/v1/ledger/{$path}")->assertForbidden();
    }
});

it('shows a branch-limited manager only their branch\'s books', function () {
    lgSignIn('manager.samahuzai@mekanikomore.ph');
    $detailing = $this->world->id('samahuzai-binan');
    $repair = $this->world->id('mekanikomor-binan');

    $entries = $this->getJson('/api/v1/ledger/journal?per_page=100')->assertOk()->json('data');
    expect($entries)->not->toBe([]);
    foreach ($entries as $entry) {
        expect([$entry['branch_id'], $entry['counter_branch_id']])->toContain($detailing);
    }
    // An entry of the other branch is a 404, like a missing one.
    $other = JournalEntry::query()->withoutGlobalScopes()->where('branch_id', $repair)->whereNull('counter_branch_id')->firstOrFail();
    $this->getJson("/api/v1/ledger/journal/{$other->id}")->assertNotFound();
    // Their reports are theirs only, and they cannot close a month.
    $this->getJson('/api/v1/ledger/reports/profit-and-loss?from=2026-07-01&to=2026-10-31')->assertOk()->assertJsonPath('data.revenue', []);
    $this->postJson('/api/v1/ledger/periods/close', ['period' => '2026-07'])->assertForbidden();
});

// ---------------------------------------------------------------------- export

it('exports the journal as CSV, one row per line, balanced', function () {
    lgSignIn();

    $response = $this->get('/api/v1/ledger/journal/export?from=2026-07-01&to=2026-10-31', ['Accept' => 'text/csv'])->assertOk();
    expect($response->headers->get('Content-Disposition'))->toContain('journal-2026-07-01-2026-10-31.csv');
    $rows = array_map('str_getcsv', array_filter(explode("\n", ltrim($response->getContent(), "\xEF\xBB\xBF"))));
    $header = array_shift($rows);
    expect($header)->toBe(['Entry', 'Date', 'Event', 'Reference', 'Memo', 'Branch', 'Account code', 'Account', 'Debit', 'Credit', 'Customer', 'Line memo', 'Reverses']);

    $debits = 0;
    $credits = 0;
    foreach ($rows as $row) {
        $debits += (int) round((float) $row[8] * 100);
        $credits += (int) round((float) $row[9] * 100);
    }
    expect($rows)->not->toBe([])->and($debits)->toBe($credits);
    $lines = JournalLine::query()->withoutGlobalScopes()->where('organization_id', lgOrg()->id)->count();
    expect(count($rows))->toBe($lines);
});

it('exports Xero or QuickBooks only for the chosen product, with every account mapped', function () {
    lgSignIn();

    $this->get('/api/v1/ledger/journal/export?format=xero&from=2026-07-01&to=2026-10-31')->assertStatus(409)->assertJsonPath('error.details.reason', 'export_target_mismatch');

    $this->putJson('/api/v1/ledger/settings', ['accounting_target' => 'xero'])->assertOk()->assertJsonPath('data.accounting_target', 'xero');
    $this->get('/api/v1/ledger/journal/export?format=quickbooks&from=2026-07-01&to=2026-10-31')->assertStatus(409)->assertJsonPath('error.details.reason', 'export_target_mismatch');

    // Nothing is mapped yet: the file is refused and the gaps are named.
    $refused = $this->get('/api/v1/ledger/journal/export?format=xero&from=2026-07-01&to=2026-10-31')->assertStatus(409);
    $missing = $refused->json('error.details.account_codes');
    expect($refused->json('error.details.reason'))->toBe('unmapped_accounts')->and($missing)->toContain('1100', '4010', '2200');

    $chart = collect($this->getJson('/api/v1/ledger/accounts')->json('data'))->keyBy('code');
    $this->putJson('/api/v1/ledger/export-mappings', ['mappings' => array_map(fn (string $code): array => ['account_id' => $chart[$code]['id'], 'target' => 'xero', 'external_code' => 'X'.$code], $missing)])->assertNoContent();

    $file = $this->get('/api/v1/ledger/journal/export?format=xero&from=2026-07-01&to=2026-10-31')->assertOk();
    $rows = array_map('str_getcsv', array_filter(explode("\n", ltrim($file->getContent(), "\xEF\xBB\xBF"))));
    expect(array_shift($rows))->toBe(['*Narration', '*Date', 'Description', '*AccountCode', '*TaxRate', '*Amount', 'TrackingName1', 'TrackingOption1'])
        ->and(array_sum(array_map(fn (array $r): int => (int) round((float) $r[5] * 100), $rows)))->toBe(0)
        ->and($rows[0][3])->toStartWith('X')
        ->and($rows[0][1])->toMatch('#^\d{2}/\d{2}/\d{4}$#');
});

it('exports QuickBooks journals with debits and credits in their own columns', function () {
    lgSignIn();
    $this->putJson('/api/v1/ledger/settings', ['accounting_target' => 'quickbooks'])->assertOk();
    $chart = collect($this->getJson('/api/v1/ledger/accounts')->json('data'));
    $this->putJson('/api/v1/ledger/export-mappings', ['mappings' => $chart->map(fn (array $a): array => ['account_id' => $a['id'], 'target' => 'quickbooks', 'external_name' => "QB {$a['name']}"])->values()->all()])->assertNoContent();

    $file = $this->get('/api/v1/ledger/journal/export?format=quickbooks&from=2026-07-01&to=2026-10-31')->assertOk();
    $rows = array_map('str_getcsv', array_filter(explode("\n", ltrim($file->getContent(), "\xEF\xBB\xBF"))));
    expect(array_shift($rows))->toBe(['Journal No', 'Journal Date', 'Account Name', 'Debits', 'Credits', 'Description', 'Name', 'Location'])
        ->and($rows[0][2])->toStartWith('QB ')
        ->and($rows[0][1])->toMatch('#^\d{2}/\d{2}/\d{4}$#');
});

// ------------------------------------------------------- what the database holds

it('refuses an unbalanced entry at commit, and an entry with fewer than two lines', function () {
    $org = $this->world->id('mekanikomor-binan');
    $entry = JournalEntry::query()->withoutGlobalScopes()->firstOrFail();

    // A fresh entry whose lines do not add up.
    $insert = function (int $debit, int $credit) use ($entry): void {
        $id = strtolower((string) Str::ulid());
        DB::table('journal_entries')->insert([
            'id' => $id, 'organization_id' => $entry->organization_id, 'branch_id' => $entry->branch_id, 'number' => 'JE-TEST-'.random_int(1, 99999),
            'entry_date' => '2026-10-08', 'period_id' => $entry->period_id, 'event' => 'stock_issue', 'source_type' => 'stock_move', 'source_id' => strtolower((string) Str::ulid()),
            'total_cents' => $debit, 'posted_by_name' => 'Test', 'posted_at' => now(),
        ]);
        $accounts = Account::query()->withoutGlobalScopes()->limit(2)->pluck('id');
        foreach ([[$accounts[0], $debit, 0], [$accounts[1], 0, $credit]] as $position => [$account, $dr, $cr]) {
            DB::table('journal_lines')->insert(['id' => strtolower((string) Str::ulid()), 'organization_id' => $entry->organization_id, 'journal_entry_id' => $id, 'position' => $position, 'account_id' => $account, 'branch_id' => $entry->branch_id, 'debit_cents' => $dr, 'credit_cents' => $cr]);
        }
        DB::statement('set constraints all immediate');
    };

    expect(fn () => DB::transaction(fn () => $insert(100, 99)))->toThrow(QueryException::class, 'does not balance');
    DB::statement('set constraints all deferred');
    expect(fn () => DB::transaction(fn () => $insert(100, 100)))->not->toThrow(QueryException::class);
    DB::statement('set constraints all deferred');

    $lonely = function () use ($entry): void {
        $id = strtolower((string) Str::ulid());
        DB::table('journal_entries')->insert([
            'id' => $id, 'organization_id' => $entry->organization_id, 'branch_id' => $entry->branch_id, 'number' => 'JE-TEST-'.random_int(1, 99999),
            'entry_date' => '2026-10-08', 'period_id' => $entry->period_id, 'event' => 'stock_issue', 'source_type' => 'stock_move', 'source_id' => strtolower((string) Str::ulid()),
            'total_cents' => 0, 'posted_by_name' => 'Test', 'posted_at' => now(),
        ]);
        DB::statement('set constraints all immediate');
    };
    expect(fn () => DB::transaction($lonely))->toThrow(QueryException::class, 'at least two');
    DB::statement('set constraints all deferred');
    expect($org)->not->toBeNull();
});

it('never updates or deletes a journal entry or line, and never posts the same event twice for a source', function () {
    $entry = JournalEntry::query()->withoutGlobalScopes()->firstOrFail();
    $line = JournalLine::query()->withoutGlobalScopes()->firstOrFail();

    foreach ([
        fn () => DB::table('journal_entries')->where('id', $entry->id)->update(['memo' => 'changed']),
        fn () => DB::table('journal_entries')->where('id', $entry->id)->delete(),
        fn () => DB::table('journal_lines')->where('id', $line->id)->update(['debit_cents' => 1]),
        fn () => DB::table('journal_lines')->where('id', $line->id)->delete(),
    ] as $mutation) {
        try {
            DB::transaction($mutation);
            $this->fail('A journal row was mutated.');
        } catch (QueryException $e) {
            expect($e->getCode())->toBe('23001');
        }
    }

    // The same event for the same source is one entry (the backfill and a retry rely on it).
    expect(fn () => DB::transaction(fn () => DB::table('journal_entries')->insert([
        'id' => strtolower((string) Str::ulid()), 'organization_id' => $entry->organization_id, 'branch_id' => $entry->branch_id, 'number' => 'JE-DUP-1',
        'entry_date' => $entry->entry_date->toDateString(), 'period_id' => $entry->period_id, 'event' => $entry->event->value, 'source_type' => $entry->source_type, 'source_id' => $entry->source_id,
        'total_cents' => 0, 'posted_by_name' => 'Test', 'posted_at' => now(),
    ])))->toThrow(QueryException::class);
});

it('refuses, in the database, an entry in a closed period or dated outside its period', function () {
    lgSignIn();
    foreach (['2026-07', '2026-08', '2026-09'] as $month) {
        $this->postJson('/api/v1/ledger/periods/close', ['period' => $month])->assertOk();
    }
    $entry = JournalEntry::query()->withoutGlobalScopes()->firstOrFail();
    $september = DB::table('periods')->where('period_key', '2026-09')->first();
    $october = DB::table('periods')->where('period_key', '2026-10')->first();

    $row = fn (string $periodId, string $date): array => [
        'id' => strtolower((string) Str::ulid()), 'organization_id' => $entry->organization_id, 'branch_id' => $entry->branch_id, 'number' => 'JE-P-'.random_int(1, 99999),
        'entry_date' => $date, 'period_id' => $periodId, 'event' => 'stock_issue', 'source_type' => 'stock_move', 'source_id' => strtolower((string) Str::ulid()),
        'total_cents' => 0, 'posted_by_name' => 'Test', 'posted_at' => now(),
    ];

    expect(fn () => DB::transaction(fn () => DB::table('journal_entries')->insert($row($september->id, '2026-09-30'))))->toThrow(QueryException::class, 'is closed');
    expect(fn () => DB::transaction(fn () => DB::table('journal_entries')->insert($row($october->id, '2026-09-30'))))->toThrow(QueryException::class, 'outside period');
    // A closed period is final.
    expect(fn () => DB::transaction(fn () => DB::table('periods')->where('id', $september->id)->update(['status' => 'open', 'closed_at' => null])))->toThrow(QueryException::class, 'is closed');
    expect(fn () => DB::transaction(fn () => DB::table('periods')->where('id', $september->id)->delete()))->toThrow(QueryException::class);
});

it('fixes an account\'s code once it has been posted to, even for raw SQL', function () {
    $posted = DB::table('accounts')->where('code', '1100')->first();
    $unused = DB::table('accounts')->where('code', '4030')->first();

    expect(fn () => DB::transaction(fn () => DB::table('accounts')->where('id', $posted->id)->update(['code' => '1199'])))->toThrow(QueryException::class, 'has been posted to');
    expect(fn () => DB::transaction(fn () => DB::table('accounts')->where('id', $unused->id)->delete()))->toThrow(QueryException::class, 'part of the books');
    DB::table('accounts')->where('id', $posted->id)->update(['name' => 'Trade receivables']);
    expect(DB::table('accounts')->where('id', $posted->id)->value('name'))->toBe('Trade receivables');
});

it('installs the lean chart once, with a rule for every key, and never twice', function () {
    lgSignIn();

    $chart = collect($this->getJson('/api/v1/ledger/accounts')->json('data'));
    $codes = $chart->pluck('name', 'code');
    expect($chart)->toHaveCount(count(ChartOfAccounts::defaults()))
        ->and($codes['1000'])->toBe('Cash on Hand')
        ->and($codes['1020'])->toBe('GCash Clearing')
        ->and($codes['1100'])->toBe('Accounts Receivable')
        ->and($codes['2100'])->toBe('GR/IR Clearing')
        ->and($codes['3000'])->toBe('Opening Balance Equity')
        ->and($codes['6000'])->toBe('Equipment Repairs & Maintenance')
        ->and($chart->firstWhere('code', '4900')['normal_side'])->toBe('debit');

    // Installing again (a second first-use) changes nothing.
    $before = Account::query()->withoutGlobalScopes()->count();
    asSystem(fn () => app(PostingRules::class)->map(lgOrg()->id));
    expect(Account::query()->withoutGlobalScopes()->count())->toBe($before);
});

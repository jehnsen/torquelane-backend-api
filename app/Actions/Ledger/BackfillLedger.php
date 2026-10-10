<?php

declare(strict_types=1);

namespace App\Actions\Ledger;

use App\Database\Cell;
use App\Domain\Inventory\MoveRequest;
use App\Domain\Inventory\MoveType;
use App\Domain\Inventory\NegativeStockPolicy;
use App\Domain\Inventory\StockLedger;
use App\Domain\Inventory\StockState;
use App\Domain\Ledger\LedgerEvent;
use App\Domain\Shared\Calendar;
use App\Exceptions\ConflictException;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\StockMove;
use App\Tenancy\TenantManager;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Posts the entries for money and stock events that happened before the
 * ledger existed (or while it was switched off). Safe to run again and again:
 * a source with an entry is skipped, so a second run posts nothing, and a
 * crash half-way leaves only whole documents posted (each is its own
 * transaction).
 *
 * Entries are dated when the event really happened and posted in date order,
 * so their numbers read in date order. A void is dated the day it was made.
 * A source that falls in a CLOSED period is reported, not posted (the books
 * for that month are final).
 */
final class BackfillLedger
{
    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly Ledger $ledger,
        private readonly LedgerPostings $postings,
    ) {}

    /**
     * @return array{posted: array<string, int>, skipped_closed: int}
     */
    public function organization(Organization $organization, bool $dryRun = false): array
    {
        return $this->tenancy->system('ledger backfill', function () use ($organization, $dryRun): array {
            return $this->ledger->backfilling(function () use ($organization, $dryRun): array {
                $posted = [];
                $skipped = 0;
                foreach ($this->work($organization->id) as $job) {
                    if ($dryRun) {
                        $posted[$job['kind']] = ($posted[$job['kind']] ?? 0) + 1;

                        continue;
                    }
                    try {
                        DB::transaction($job['run']);
                        $posted[$job['kind']] = ($posted[$job['kind']] ?? 0) + 1;
                    } catch (ConflictException $e) {
                        if (($e->details()['reason'] ?? null) !== 'period_closed') {
                            throw $e;
                        }
                        $skipped++;
                    }
                }

                return ['posted' => $posted, 'skipped_closed' => $skipped];
            });
        });
    }

    /**
     * Everything still unposted, as runnable jobs sorted by (date, kind order, id).
     *
     * @return list<array{kind: string, run: \Closure(): mixed}>
     */
    private function work(string $organizationId): array
    {
        $posted = fn (LedgerEvent $event): array => array_flip(array_map(Cell::string(...), DB::table('journal_entries')->where('organization_id', $organizationId)->where('event', $event->value)->pluck('source_id')->all()));
        $jobs = [];
        $add = function (string $date, int $order, string $id, string $kind, \Closure $run) use (&$jobs): void {
            $jobs[] = ['sort' => [$date, $order, $id], 'kind' => $kind, 'run' => $run];
        };

        $issued = $posted(LedgerEvent::InvoiceIssued);
        $invoiceVoids = $posted(LedgerEvent::InvoiceVoided);
        foreach (Invoice::query()->where('organization_id', $organizationId)->where('status', '<>', 'draft')->orderBy('issue_date')->orderBy('id')->get() as $invoice) {
            if (! isset($issued[$invoice->id])) {
                $add((string) $invoice->issue_date?->toDateString(), 10, $invoice->id, 'invoices', fn () => $this->postings->invoiceIssued($invoice));
            }
            if ($invoice->voided_at !== null && ! isset($invoiceVoids[$invoice->id])) {
                $on = Calendar::toDate($invoice->voided_at);
                $add($on, 40, $invoice->id, 'invoice voids', fn () => $this->postings->invoiceVoided($invoice, (string) $invoice->void_reason, $on));
            }
        }

        $received = $posted(LedgerEvent::PaymentReceived);
        $paymentVoids = $posted(LedgerEvent::PaymentVoided);
        $payments = Payment::query()->where('organization_id', $organizationId)->orderBy('received_on')->orderBy('id')->get()->keyBy('id');
        foreach ($payments as $payment) {
            if (! isset($received[$payment->id])) {
                $add($payment->received_on->toDateString(), 20, $payment->id, 'payments', fn () => $this->postings->paymentReceived($payment));
            }
            if ($payment->voided_at !== null && ! isset($paymentVoids[$payment->id])) {
                $on = Calendar::toDate($payment->voided_at);
                $add($on, 50, $payment->id, 'payment voids', fn () => $this->postings->paymentVoided($payment, (string) $payment->void_reason, $on));
            }
        }

        $applied = $posted(LedgerEvent::CreditApplied);
        $invoices = Invoice::query()->where('organization_id', $organizationId)->get()->keyBy('id');
        foreach (PaymentAllocation::query()->where('organization_id', $organizationId)->orderBy('allocated_on')->orderBy('id')->get() as $allocation) {
            $payment = $payments->get($allocation->payment_id);
            $invoice = $invoices->get($allocation->invoice_id);
            if (! isset($applied[$allocation->id]) && $payment !== null && $invoice !== null) {
                $add($allocation->allocated_on->toDateString(), 30, $allocation->id, 'credit applications', fn () => $this->postings->creditApplied($allocation, $payment, $invoice));
            }
        }

        foreach ($this->stockJobs($organizationId) as [$date, $id, $kind, $run]) {
            $add($date, 5, $id, $kind, $run);
        }

        usort($jobs, fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        return array_map(fn (array $job): array => ['kind' => $job['kind'], 'run' => $job['run']], $jobs);
    }

    /**
     * Stock moves with no journal line. Each balance's moves are replayed through the real ledger maths in order, so every
     * move gets the book-value change it caused; the two halves of a transfer post as one entry.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: \Closure(): mixed}>
     */
    private function stockJobs(string $organizationId): array
    {
        $done = array_flip(array_map(Cell::string(...), DB::table('journal_lines')->where('organization_id', $organizationId)->whereNotNull('stock_move_id')->pluck('stock_move_id')->all()));
        $moves = StockMove::query()->where('organization_id', $organizationId)->orderBy('location_id')->orderBy('item_id')->orderBy('id')->get();
        if ($moves->isEmpty()) {
            return [];
        }
        $itemTypes = Item::query()->whereIn('id', $moves->pluck('item_id')->unique()->all())->get()->keyBy('id');

        /** @var array<string, int> $delta */
        $delta = [];
        $state = null;
        $group = null;
        foreach ($moves as $move) {
            $key = $move->location_id.'|'.$move->item_id;
            if ($key !== $group) {
                $state = new StockState(BigDecimal::zero(), 0);
                $group = $key;
            }
            $before = StockLedger::valuation($state);
            $state = StockLedger::applyMove($state, new MoveRequest($move->move_type, $move->quantity, $move->unit_cost_cents), NegativeStockPolicy::AllowAndFlag)->after;
            $delta[$move->id] = StockLedger::valuation($state) - $before;
        }

        $jobs = [];
        $transfers = [];
        foreach ($moves as $move) {
            if (isset($done[$move->id])) {
                continue;
            }
            if ($move->move_type === MoveType::TransferOut || $move->move_type === MoveType::TransferIn) {
                $transfers[$move->source_id.'|'.$move->item_id][$move->move_type->value] = $move;

                continue;
            }
            $type = $itemTypes->get($move->item_id)?->item_type;
            $date = Calendar::toDate($move->occurred_at);
            $jobs[] = [$date, $move->id, 'stock moves', fn () => $this->postings->stockMove($move, $delta[$move->id], $type)];
        }
        foreach ($transfers as $pair) {
            $out = $pair[MoveType::TransferOut->value] ?? null;
            $in = $pair[MoveType::TransferIn->value] ?? null;
            if ($out === null || $in === null) {
                continue;
            }
            $jobs[] = [Calendar::toDate($out->occurred_at), $out->id, 'stock transfers', fn () => $this->postings->stockTransfer($out, $delta[$out->id], $in, $delta[$in->id])];
        }

        return $jobs;
    }
}

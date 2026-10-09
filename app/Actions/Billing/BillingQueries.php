<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Actions\WorkOrders\WorkOrderQueries;
use App\Actions\WorkOrders\WorkOrderView;
use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Receivables\Aging;
use App\Domain\Receivables\CreditPosition;
use App\Domain\Receivables\OpenInvoice;
use App\Domain\Receivables\PaymentStatus;
use App\Domain\Receivables\Statement;
use App\Domain\Receivables\StatementEntry;
use App\Domain\Shared\Calendar;
use App\Domain\WorkOrders\WorkOrderStatus;
use App\Models\CustomerAccount;
use App\Models\Invoice;
use App\Models\InvoiceWorkOrder;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\DB;

/**
 * The read side of billing, always within what the caller may see: staff the
 * branches they work in (the selected one, or all), a portal user their own
 * account's issued invoices and payments.
 *
 * @phpstan-type InvoiceFilters array{status?: string, customer_account_id?: string, branch_id?: string, q?: string}
 * @phpstan-type PaymentFilters array{status?: string, method?: string, customer_account_id?: string, branch_id?: string, q?: string}
 */
final class BillingQueries
{
    public const array INVOICE_RELATIONS = ['lines', 'workOrderLinks.workOrder', 'allocations.payment', 'customerAccount'];

    public const array PAYMENT_RELATIONS = ['allocations.invoice', 'customerAccount'];

    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly WorkOrderQueries $orders,
    ) {}

    /**
     * @return Builder<Invoice>
     */
    public function invoices(): Builder
    {
        $context = $this->tenancy->require();
        $query = Invoice::query()->visibleTo($context);
        if ($context->isStaff()) {
            $query->whereIn('branch_id', $context->branchFilter());
        }

        return $query;
    }

    /**
     * @return Builder<Payment>
     */
    public function payments(): Builder
    {
        $context = $this->tenancy->require();
        $query = Payment::query()->visibleTo($context);
        if ($context->isStaff()) {
            $query->whereIn('branch_id', $context->branchFilter());
        }

        return $query;
    }

    /**
     * Newest first; drafts (undated) ahead of everything issued. `status`: one
     * of the statuses, or `open` (issued or partially paid) or `overdue` (open
     * and past due).
     *
     * @param  InvoiceFilters  $filters
     * @return LengthAwarePaginator<int, Invoice>
     */
    public function invoicePage(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = $this->invoices()->with(self::INVOICE_RELATIONS);
        $today = Calendar::toDate(CarbonImmutable::now());
        match ($filters['status'] ?? null) {
            null => null,
            'open' => $query->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value]),
            'overdue' => $query->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])->where('due_date', '<', $today),
            default => $query->where('status', $filters['status']),
        };
        foreach (['customer_account_id', 'branch_id'] as $column) {
            if (isset($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }
        if (($filters['q'] ?? '') !== '') {
            $like = '%'.addcslashes(mb_strtolower((string) $filters['q']), '%_\\').'%';
            $query->where(fn (Builder $w) => $w->whereRaw("lower(coalesce(number, '')) like ?", [$like])->orWhereRaw('lower(buyer_name) like ?', [$like]));
        }

        return $query->orderByRaw('issue_date desc nulls first')->orderByDesc('number')->orderByDesc('id')->paginate($perPage);
    }

    /**
     * @param  PaymentFilters  $filters
     * @return LengthAwarePaginator<int, Payment>
     */
    public function paymentPage(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = $this->payments()->with(self::PAYMENT_RELATIONS);
        foreach (['status', 'method', 'customer_account_id', 'branch_id'] as $column) {
            if (isset($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }
        if (($filters['q'] ?? '') !== '') {
            $like = '%'.addcslashes(mb_strtolower((string) $filters['q']), '%_\\').'%';
            $query->where(fn (Builder $w) => $w->whereRaw('lower(number) like ?', [$like])->orWhereRaw("lower(coalesce(reference_no, '')) like ?", [$like]));
        }

        return $query->orderByDesc('received_on')->orderByDesc('number')->orderByDesc('id')->paginate($perPage);
    }

    /**
     * The billing queue (staff): closed jobs not yet invoiced, and not settled
     * before invoicing existed. Oldest finished first, so nothing waits long.
     *
     * @return LengthAwarePaginator<int, WorkOrderView>
     */
    public function queue(?string $customerAccountId, int $perPage): LengthAwarePaginator
    {
        $context = $this->tenancy->require();
        $query = $this->orders->orders()
            ->with(WorkOrderQueries::RELATIONS)
            ->where('status', WorkOrderStatus::Closed->value)
            ->whereNull('collected_at')
            ->whereIn('branch_id', $context->branchFilter())
            ->whereNotIn('id', InvoiceWorkOrder::query()->select('work_order_id')->whereNull('released_at'));
        if ($customerAccountId !== null) {
            $query->where('customer_account_id', $customerAccountId);
        }
        $page = $query->orderByRaw('completed_on asc nulls last')->orderBy('id')->paginate($perPage);

        return new Paginator($this->orders->views(array_values($page->items())), $page->total(), $page->perPage(), $page->currentPage());
    }

    /**
     * Open items as of a business date: every invoice issued on or before it
     * and not voided by then, with what had been paid against it by then
     * (allocations dated on or before it, from payments not voided by then).
     *
     * @return list<OpenInvoice>
     */
    public function openItems(string $asOf, ?string $customerAccountId = null): array
    {
        $query = $this->invoices()
            ->with(['allocations.payment'])
            ->where('status', '<>', InvoiceStatus::Draft->value)
            ->where('issue_date', '<=', $asOf);
        if ($customerAccountId !== null) {
            $query->where('customer_account_id', $customerAccountId);
        }

        $items = [];
        foreach ($query->orderBy('due_date')->orderBy('number')->get() as $invoice) {
            if ($invoice->voided_at !== null && Calendar::toDate($invoice->voided_at) <= $asOf) {
                continue;
            }
            $paid = 0;
            foreach ($invoice->allocations as $allocation) {
                $payment = $allocation->payment;
                $standing = $payment->status === PaymentStatus::Posted || ($payment->voided_at !== null && Calendar::toDate($payment->voided_at) > $asOf);
                if ($standing && $allocation->allocated_on->toDateString() <= $asOf) {
                    $paid += $allocation->amount_cents;
                }
            }
            $items[] = new OpenInvoice($invoice->id, (string) $invoice->number, $invoice->customer_account_id, (string) $invoice->issue_date?->toDateString(), (string) $invoice->due_date?->toDateString(), $invoice->total_due_cents, $paid);
        }

        return $items;
    }

    /**
     * Receivables aging as of a date, per account (largest balance first).
     *
     * @return array{as_of: string, accounts: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function aging(string $asOf, ?string $customerAccountId = null): array
    {
        $report = Aging::report($this->openItems($asOf, $customerAccountId), $asOf);
        $names = CustomerAccount::query()->whereIn('id', array_keys($report['accounts']))->pluck('display_name', 'id');
        $credits = $this->credits(array_keys($report['accounts']));

        $rows = [];
        foreach ($report['accounts'] as $accountId => $buckets) {
            $name = $names->get($accountId);
            $rows[] = ['customer_account_id' => $accountId, 'customer_name' => is_string($name) ? $name : ''] + $buckets + ['credit_cents' => $credits[$accountId] ?? 0];
        }
        usort($rows, fn (array $a, array $b): int => [$b['total'], $a['customer_name']] <=> [$a['total'], $b['customer_name']]);

        return ['as_of' => $asOf, 'accounts' => $rows, 'totals' => $report['totals']];
    }

    /**
     * Where an account stands against its credit limit, over EVERY branch of
     * the organization (a limit is the account's, not a branch's).
     */
    public function creditPosition(CustomerAccount $account): CreditPosition
    {
        $outstanding = (int) Invoice::query()
            ->where('customer_account_id', $account->id)
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])
            ->sum(DB::raw('total_due_cents - paid_cents'));

        return new CreditPosition($account->credit_limit_cents?->getMinorAmount()->toInt(), $outstanding, $this->allCredit($account->id));
    }

    /**
     * The customer's balance tab.
     *
     * @return array{customer_account_id: string, customer_name: string, payment_terms_days: int, credit_limit_cents: int|null, outstanding_cents: int, overdue_cents: int, credit_cents: int, net_balance_cents: int, available_credit_cents: int|null, over_limit: bool, open_invoices: list<Invoice>, next_due_date: string|null, uninvoiced_jobs: int|null}
     */
    public function balance(CustomerAccount $account): array
    {
        $today = Calendar::toDate(CarbonImmutable::now());
        $position = $this->creditPosition($account);
        $open = $this->invoices()
            ->where('customer_account_id', $account->id)
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])
            ->orderBy('due_date')->orderBy('number')
            ->get();

        $overdue = 0;
        foreach ($open as $invoice) {
            if ($invoice->due_date !== null && $invoice->due_date->toDateString() < $today) {
                $overdue += $invoice->balanceCents();
            }
        }

        return [
            'customer_account_id' => $account->id,
            'customer_name' => $account->display_name,
            'payment_terms_days' => $account->payment_terms_days,
            'credit_limit_cents' => $position->creditLimitCents,
            'outstanding_cents' => $position->outstandingCents,
            'overdue_cents' => $overdue,
            'credit_cents' => $position->creditCents,
            'net_balance_cents' => $position->exposureCents(),
            'available_credit_cents' => $position->availableCents(),
            'over_limit' => $position->isOverLimit(),
            'open_invoices' => array_values($open->all()),
            'next_due_date' => $open->first()?->due_date?->toDateString(),
            'uninvoiced_jobs' => $this->tenancy->require()->isStaff() ? $this->queue($account->id, 1)->total() : null,
        ];
    }

    /**
     * A statement of account over [from, to]: the balance brought forward,
     * every invoice, payment and void in the range with the running balance,
     * and the closing balance.
     *
     * @return array<string, mixed>
     */
    public function statement(CustomerAccount $account, string $from, string $to): array
    {
        $entries = [];
        foreach ($this->invoices()->where('customer_account_id', $account->id)->where('status', '<>', InvoiceStatus::Draft->value)->get() as $invoice) {
            $number = (string) $invoice->number;
            $entries[] = new StatementEntry((string) $invoice->issue_date?->toDateString(), StatementEntry::INVOICE, $invoice->id, $number, 'Invoice '.$number, $invoice->total_due_cents, 0, ($invoice->issued_at?->toIso8601ZuluString() ?? '').$invoice->id);
            if ($invoice->voided_at !== null) {
                $entries[] = new StatementEntry(Calendar::toDate($invoice->voided_at), StatementEntry::INVOICE_VOID, $invoice->id, $number, 'Invoice '.$number.' voided', 0, $invoice->total_due_cents, $invoice->voided_at->toIso8601ZuluString().$invoice->id);
            }
        }
        foreach ($this->payments()->where('customer_account_id', $account->id)->get() as $payment) {
            $label = sprintf('Payment %s (%s%s)', $payment->number, $payment->method->label(), $payment->reference_no === null ? '' : ' '.$payment->reference_no);
            $entries[] = new StatementEntry($payment->received_on->toDateString(), StatementEntry::PAYMENT, $payment->id, $payment->number, $label, 0, $payment->amount_cents, $payment->created_at->toIso8601ZuluString().$payment->id);
            if ($payment->voided_at !== null) {
                $entries[] = new StatementEntry(Calendar::toDate($payment->voided_at), StatementEntry::PAYMENT_VOID, $payment->id, $payment->number, 'Payment '.$payment->number.' voided', $payment->amount_cents, 0, $payment->voided_at->toIso8601ZuluString().$payment->id);
            }
        }

        $statement = Statement::build($entries, $from, $to);

        return [
            'customer_account_id' => $account->id,
            'customer_name' => $account->registered_name ?? $account->display_name,
            'customer_tin' => $account->tin,
            'customer_address' => $account->address,
            'from' => $from,
            'to' => $to,
            'opening_balance_cents' => $statement['opening_balance_cents'],
            'total_charges_cents' => $statement['total_charges_cents'],
            'total_credits_cents' => $statement['total_credits_cents'],
            'closing_balance_cents' => $statement['closing_balance_cents'],
            'entries' => array_map(fn (array $row): array => [
                'date' => $row['entry']->date,
                'kind' => $row['entry']->kind,
                'document_id' => $row['entry']->documentId,
                'reference' => $row['entry']->reference,
                'description' => $row['entry']->description,
                'charge_cents' => $row['entry']->chargeCents,
                'credit_cents' => $row['entry']->creditCents,
                'balance_cents' => $row['balance_cents'],
            ], $statement['entries']),
        ];
    }

    /**
     * Revenue two ways over [from, to]: ACCRUAL (invoices issued in the range
     * and still standing: net sales, VAT, total) and CASH (payments received
     * in the range that still stand, by method).
     *
     * @return array<string, mixed>
     */
    public function revenue(string $from, string $to): array
    {
        $issued = $this->invoices()
            ->whereNotIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Void->value])
            ->whereBetween('issue_date', [$from, $to])
            ->get();
        $net = 0;
        $vat = 0;
        $total = 0;
        foreach ($issued as $invoice) {
            $net += $invoice->totals()->netSalesCents();
            $vat += $invoice->vat_amount_cents;
            $total += $invoice->total_due_cents;
        }

        $received = $this->payments()
            ->where('status', PaymentStatus::Posted->value)
            ->whereBetween('received_on', [$from, $to])
            ->get();
        $byMethod = [];
        foreach ($received as $payment) {
            $byMethod[$payment->method->value] = ($byMethod[$payment->method->value] ?? 0) + $payment->amount_cents;
        }
        ksort($byMethod);

        return [
            'from' => $from,
            'to' => $to,
            'accrual' => ['invoices' => $issued->count(), 'net_sales_cents' => $net, 'vat_cents' => $vat, 'total_cents' => $total],
            'cash' => [
                'payments' => $received->count(),
                'received_cents' => array_sum($byMethod),
                'by_method' => array_map(fn (string $method, int $cents): array => ['method' => $method, 'received_cents' => $cents], array_keys($byMethod), array_values($byMethod)),
            ],
        ];
    }

    /**
     * Unallocated credit per account, over the caller's visible payments.
     *
     * @param  list<string>  $accountIds
     * @return array<string, int>
     */
    private function credits(array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }
        $credits = [];
        foreach ($this->payments()->with('allocations')->whereIn('customer_account_id', $accountIds)->where('status', PaymentStatus::Posted->value)->get() as $payment) {
            $credits[$payment->customer_account_id] = ($credits[$payment->customer_account_id] ?? 0) + $payment->unallocatedCents();
        }

        return $credits;
    }

    /** An account's unallocated credit over every branch. */
    private function allCredit(string $accountId): int
    {
        $paid = (int) Payment::query()->where('customer_account_id', $accountId)->where('status', PaymentStatus::Posted->value)->sum('amount_cents');
        $allocated = (int) PaymentAllocation::query()
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->where('payment_allocations.customer_account_id', $accountId)
            ->where('payments.status', PaymentStatus::Posted->value)
            ->sum('payment_allocations.amount_cents');

        return $paid - $allocated;
    }
}

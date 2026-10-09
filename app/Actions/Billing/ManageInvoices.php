<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Actions\Numbering\DocumentNumbers;
use App\Actions\WorkOrders\ApprovalSettingsResolver;
use App\Domain\Approvals\LineApprovalStatus;
use App\Domain\Inventory\TaxClass;
use App\Domain\Invoicing\BillableJob;
use App\Domain\Invoicing\BillableJobLine;
use App\Domain\Invoicing\InvoiceLineDraft;
use App\Domain\Invoicing\InvoiceLineKind;
use App\Domain\Invoicing\InvoiceSource;
use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Invoicing\Invoicing;
use App\Domain\Numbering\DocumentType;
use App\Domain\Shared\Calendar;
use App\Domain\WorkOrders\WorkOrderStatus;
use App\Events\InvoiceIssued;
use App\Events\InvoiceVoided;
use App\Exceptions\ConflictException;
use App\Exceptions\InvalidTransitionException;
use App\Models\Branch;
use App\Models\CustomerAccount;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceWorkOrder;
use App\Models\Item;
use App\Models\WorkOrder;
use App\Models\WorkOrderLine;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * An invoice's life: raised as a draft (from closed jobs of one account, or
 * typed in), edited while a draft, then issued (numbered from the `invoice`
 * series in the same transaction, R8, and frozen, R7) or discarded; an issued
 * invoice with nothing paid against it may be voided (it keeps its number,
 * and its jobs may be invoiced again).
 *
 * A work order is invoiced once: raising a draft for it claims it (row lock,
 * and the database's partial unique index on the standing link), and only a
 * void or a discarded draft lets it go. Its lines are billed at the STORED
 * approved costs (R11); nothing the client sends prices a job line.
 */
final class ManageInvoices
{
    /** One invoice carries at most this many jobs. */
    public const int MAX_JOBS = 50;

    public function __construct(
        private readonly BillingJournal $journal,
        private readonly InvoiceSettlement $settlement,
        private readonly InvoiceParties $parties,
        private readonly ApprovalSettingsResolver $settings,
        private readonly DocumentNumbers $numbers,
    ) {}

    /**
     * A draft for closed jobs of ONE account, raised in ONE branch: every
     * approved line's parts and labour, and each job's flat fee.
     *
     * @param  list<WorkOrder>  $orders  visible to the caller (the controller authorizes each)
     */
    public function fromWorkOrders(array $orders, string $notes = ''): Invoice
    {
        if ($orders === [] || count($orders) > self::MAX_JOBS) {
            throw ValidationException::withMessages(['work_order_ids' => sprintf('Invoice between 1 and %d jobs at a time.', self::MAX_JOBS)]);
        }

        try {
            return DB::transaction(function () use ($orders, $notes): Invoice {
                $ids = array_values(array_unique(array_map(fn (WorkOrder $o): string => $o->id, $orders)));
                sort($ids);
                $locked = WorkOrder::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
                $locked->load(['lines', 'invoiceLinks']);

                $first = $locked->first() ?? throw ValidationException::withMessages(['work_order_ids' => 'Name the jobs to invoice.']);
                foreach ($locked as $order) {
                    $this->assertBillable($order, $first);
                }
                $branch = Branch::query()->findOrFail($first->branch_id);
                $account = CustomerAccount::query()->findOrFail($first->customer_account_id);

                $jobs = array_values($locked->sortBy(fn (WorkOrder $o): string => ($o->completed_on?->toDateString() ?? '').'|'.$o->reference.'|'.$o->id)->map($this->job(...))->all());
                $lines = Invoicing::linesFromJobs($jobs);
                if ($lines === []) {
                    throw ValidationException::withMessages(['work_order_ids' => 'Nothing on these jobs is billable: no approved line carries a price.']);
                }

                $invoice = $this->draft($branch, $account, InvoiceSource::WorkOrders, $lines, $notes);
                foreach ($locked as $order) {
                    $link = new InvoiceWorkOrder;
                    $link->forceFill([
                        'invoice_id' => $invoice->id,
                        'customer_account_id' => $account->id,
                        'work_order_id' => $order->id,
                    ])->save();
                }
                $this->journal->auditInvoice($invoice, 'created', null);

                return $invoice;
            });
        } catch (QueryException $e) {
            // The partial unique index: another invoice claimed one of these jobs first.
            if ($e->getCode() === '23505' && str_contains($e->getMessage(), 'invoice_work_orders_standing_unique')) {
                throw new ConflictException('One of these jobs is already on another invoice.');
            }
            throw $e;
        }
    }

    /**
     * A typed-in draft: an account, the branch billing it, and its lines.
     *
     * @param  list<array<string, mixed>>  $lines  validated rows (description, quantity, unit_price_cents, discount_cents?, tax_class?, item_id?, service_task_id?)
     */
    public function manual(CustomerAccount $account, string $branchId, array $lines, string $notes = ''): Invoice
    {
        return DB::transaction(function () use ($account, $branchId, $lines, $notes): Invoice {
            $branch = Branch::query()->findOrFail($branchId);
            $invoice = $this->draft($branch, $account, InvoiceSource::Manual, $this->manualLines($lines), $notes);
            $this->journal->auditInvoice($invoice, 'created', null);

            return $invoice;
        });
    }

    /**
     * Edit a draft: its notes; a typed-in invoice's lines (the whole list);
     * a line's discount, on either kind.
     *
     * @param  array{notes?: string|null, lines?: list<array<string, mixed>>, discounts?: list<array{line_id: string, discount_cents: int}>}  $data
     */
    public function updateDraft(Invoice $invoice, array $data): Invoice
    {
        return DB::transaction(function () use ($invoice, $data): Invoice {
            $locked = $this->lockDraft($invoice, 'edited');
            $before = BillingJournal::invoiceSnapshot($locked);

            $lines = array_values($locked->lines()->get()->map(fn (InvoiceLine $line): InvoiceLineDraft => $line->draft())->all());
            $ids = array_values($locked->lines()->get()->map(fn (InvoiceLine $line): string => $line->id)->all());

            if (isset($data['lines'])) {
                if ($locked->source !== InvoiceSource::Manual) {
                    throw ValidationException::withMessages(['lines' => 'An invoice raised from work orders bills their approved lines; only discounts may change.']);
                }
                $lines = $this->manualLines($data['lines']);
                $ids = [];
            }
            foreach ($data['discounts'] ?? [] as $index => $discount) {
                $position = array_search($discount['line_id'], $ids, true);
                if (! is_int($position)) {
                    throw ValidationException::withMessages(["discounts.{$index}.line_id" => 'That line is not on this invoice.']);
                }
                try {
                    $lines[$position] = $lines[$position]->withDiscount($discount['discount_cents']);
                } catch (InvalidArgumentException $e) {
                    throw ValidationException::withMessages(["discounts.{$index}.discount_cents" => $e->getMessage()]);
                }
            }

            if (array_key_exists('notes', $data)) {
                $locked->notes = trim((string) $data['notes']);
            }
            $this->writeLines($locked, array_values($lines));
            $this->journal->auditInvoice($locked, 'edited', $before);

            return $locked;
        });
    }

    /** A draft that will not be issued: deleted, and its jobs are free to be invoiced. */
    public function discard(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice): void {
            $locked = $this->lockDraft($invoice, 'discarded');
            $before = BillingJournal::invoiceSnapshot($locked);
            $this->journal->auditInvoice($locked, 'discarded', $before);
            InvoiceLine::query()->where('invoice_id', $locked->id)->delete();
            InvoiceWorkOrder::query()->where('invoice_id', $locked->id)->delete();
            $locked->delete();
        });
    }

    /**
     * Issue: refresh who it is from and to and how VAT applies, total it, number
     * it, date it (today, or an earlier business date that does not run behind
     * the series), and set its due date from the account's payment terms.
     */
    public function issue(Invoice $invoice, ?string $issueDate = null): Invoice
    {
        return DB::transaction(function () use ($invoice, $issueDate): Invoice {
            $locked = $this->lockDraft($invoice, 'issued');
            $lines = array_values($locked->lines()->get()->map(fn (InvoiceLine $line): InvoiceLineDraft => $line->draft())->all());
            if ($lines === []) {
                throw ValidationException::withMessages(['lines' => 'An invoice bills at least one line.']);
            }

            $now = CarbonImmutable::now();
            $date = $this->issueDate($issueDate, $now);
            $branch = Branch::query()->findOrFail($locked->branch_id);
            $account = CustomerAccount::query()->findOrFail($locked->customer_account_id);
            $before = BillingJournal::invoiceSnapshot($locked);
            $actor = $this->journal->actor();

            $locked->forceFill($this->parties->snapshot($branch, $account));
            $locked->forceFill(Invoicing::totals($lines, $locked->vat())->toArray() + [
                'number' => $this->numbers->issue($locked->organization_id, null, DocumentType::Invoice, $now)->formatted,
                'status' => InvoiceStatus::Issued,
                'issue_date' => $date,
                'due_date' => Invoicing::dueDate($date, $account->payment_terms_days),
                'payment_terms_days' => $account->payment_terms_days,
                'issued_at' => $now,
                'issued_by_name' => $actor->name,
            ])->save();

            // Nothing due is settled the moment it is issued.
            $this->settlement->refresh($locked, $now);
            $this->journal->auditInvoice($locked, 'issued', $before);
            event(new InvoiceIssued($locked->organization_id, $locked->id, (string) $locked->number));

            return $locked;
        });
    }

    /** Void an issued invoice nothing has been paid against: it keeps its number; its jobs may be invoiced again. */
    public function void(Invoice $invoice, string $reason): Invoice
    {
        return DB::transaction(function () use ($invoice, $reason): Invoice {
            $locked = $this->journal->lockInvoice($invoice);
            match ($locked->status) {
                InvoiceStatus::Draft => throw new InvalidTransitionException('A draft has no number to void; discard it instead.'),
                InvoiceStatus::Void => throw new InvalidTransitionException("Invoice {$locked->number} is already void."),
                InvoiceStatus::PartiallyPaid, InvoiceStatus::Paid => throw new InvalidTransitionException("Invoice {$locked->number} has payments against it; void those payments first."),
                InvoiceStatus::Issued => null,
            };
            $before = BillingJournal::invoiceSnapshot($locked);
            $now = CarbonImmutable::now();

            $locked->forceFill([
                'status' => InvoiceStatus::Void,
                'voided_at' => $now,
                'voided_by_name' => $this->journal->actor()->name,
                'void_reason' => $reason,
            ])->save();
            InvoiceWorkOrder::query()->where('invoice_id', $locked->id)->whereNull('released_at')->update(['released_at' => $now]);

            $this->journal->auditInvoice($locked, 'voided', $before);
            event(new InvoiceVoided($locked->organization_id, $locked->id, (string) $locked->number));

            return $locked;
        });
    }

    private function assertBillable(WorkOrder $order, WorkOrder $first): void
    {
        $label = $order->reference !== '' ? $order->reference : 'This job';
        if ($order->status !== WorkOrderStatus::Closed) {
            throw new InvalidTransitionException("{$label} is not closed; only finished work is invoiced.");
        }
        if ($order->collected_at !== null) {
            throw new InvalidTransitionException("{$label} was settled before invoicing existed; it is not invoiced again.");
        }
        if ($order->standingInvoiceLink() !== null) {
            throw new ConflictException("{$label} is already on an invoice.");
        }
        if ($order->customer_account_id !== $first->customer_account_id) {
            throw ValidationException::withMessages(['work_order_ids' => 'One invoice bills one customer account.']);
        }
        if ($order->branch_id === null || $order->branch_id !== $first->branch_id) {
            throw ValidationException::withMessages(['work_order_ids' => 'One invoice bills the work of one branch.']);
        }
    }

    private function job(WorkOrder $order): BillableJob
    {
        $approved = $order->lines->filter(fn (WorkOrderLine $l): bool => $l->approval_status === LineApprovalStatus::Approved);
        $itemIds = array_values(array_filter($approved->map(fn (WorkOrderLine $l): ?string => $l->item_id)->all()));
        $taxClasses = $itemIds === [] ? collect() : Item::query()->whereIn('id', $itemIds)->pluck('tax_class', 'id');

        return new BillableJob(
            $order->id,
            $order->reference,
            $order->title,
            array_values($approved->map(function (WorkOrderLine $line) use ($taxClasses): BillableJobLine {
                $class = $line->item_id === null ? null : $taxClasses->get($line->item_id);

                return new BillableJobLine(
                    $line->id,
                    $line->description,
                    (string) $line->quantity,
                    $line->unit_part_rate_cents,
                    $line->part_cost_cents,
                    (string) $line->labour_hours,
                    $line->labour_rate_cents,
                    $line->labour_cost_cents,
                    $line->service_task_id,
                    $line->item_id,
                    $class instanceof TaxClass ? $class : (is_string($class) ? TaxClass::from($class) : TaxClass::Vatable),
                );
            })->all()),
            $this->settings->forOrder($order)->miscFeeFlatCents,
        );
    }

    /**
     * @param  list<InvoiceLineDraft>  $lines
     */
    private function draft(Branch $branch, CustomerAccount $account, InvoiceSource $source, array $lines, string $notes): Invoice
    {
        $actor = $this->journal->actor();
        $invoice = new Invoice;
        $invoice->forceFill($this->parties->snapshot($branch, $account) + [
            'branch_id' => $branch->id,
            'customer_account_id' => $account->id,
            'source' => $source,
            'status' => InvoiceStatus::Draft,
            'payment_terms_days' => $account->payment_terms_days,
            'notes' => trim($notes),
            'created_by' => $actor->id,
            'created_by_name' => $actor->name,
        ])->save();
        $this->writeLines($invoice, $lines);

        return $invoice;
    }

    /**
     * Replaces a draft's lines and re-totals it.
     *
     * @param  list<InvoiceLineDraft>  $lines
     */
    private function writeLines(Invoice $invoice, array $lines): void
    {
        InvoiceLine::query()->where('invoice_id', $invoice->id)->delete();
        foreach ($lines as $position => $draft) {
            $line = new InvoiceLine;
            $line->forceFill([
                'invoice_id' => $invoice->id,
                'position' => $position,
                'kind' => $draft->kind,
                'description' => $draft->description,
                'work_order_id' => $draft->workOrderId,
                'work_order_line_id' => $draft->workOrderLineId,
                'item_id' => $draft->itemId,
                'service_task_id' => $draft->serviceTaskId,
                'quantity' => $draft->quantity,
                'unit_price_cents' => $draft->unitPriceCents,
                'discount_cents' => $draft->discountCents,
                'tax_class' => $draft->taxClass,
                'line_total_cents' => $draft->totalCents(),
            ])->save();
        }
        $invoice->forceFill(Invoicing::totals($lines, $invoice->vat())->toArray())->save();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<InvoiceLineDraft>
     */
    private function manualLines(array $rows): array
    {
        if ($rows === []) {
            throw ValidationException::withMessages(['lines' => 'An invoice bills at least one line.']);
        }
        $itemIds = array_values(array_filter(array_map(fn (array $row): ?string => is_string($row['item_id'] ?? null) ? $row['item_id'] : null, $rows)));
        $known = $itemIds === [] ? [] : Item::query()->whereIn('id', $itemIds)->pluck('id')->all();

        $lines = [];
        foreach ($rows as $index => $row) {
            $itemId = is_string($row['item_id'] ?? null) ? $row['item_id'] : null;
            if ($itemId !== null && ! in_array($itemId, $known, true)) {
                throw ValidationException::withMessages(["lines.{$index}.item_id" => 'Unknown item.']);
            }
            try {
                $lines[] = new InvoiceLineDraft(
                    InvoiceLineKind::Manual,
                    trim(is_string($row['description'] ?? null) ? $row['description'] : ''),
                    is_string($row['quantity'] ?? null) || is_int($row['quantity'] ?? null) ? (string) $row['quantity'] : '0',
                    is_int($row['unit_price_cents'] ?? null) ? $row['unit_price_cents'] : 0,
                    is_int($row['discount_cents'] ?? null) ? $row['discount_cents'] : 0,
                    is_string($row['tax_class'] ?? null) ? TaxClass::from($row['tax_class']) : TaxClass::Vatable,
                    null,
                    null,
                    $itemId,
                    is_string($row['service_task_id'] ?? null) ? $row['service_task_id'] : null,
                );
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages(["lines.{$index}" => $e->getMessage()]);
            }
        }

        return $lines;
    }

    private function lockDraft(Invoice $invoice, string $verb): Invoice
    {
        $locked = $this->journal->lockInvoice($invoice);
        if ($locked->status !== InvoiceStatus::Draft) {
            throw new InvalidTransitionException("Invoice {$locked->number} has been issued; it is never {$verb} again. Void it instead.");
        }

        return $locked;
    }

    /** Today in Manila, or an earlier business date that keeps the series in date order. */
    private function issueDate(?string $requested, CarbonImmutable $now): string
    {
        $today = Calendar::toDate($now);
        if ($requested === null) {
            return $today;
        }
        if ($requested > $today) {
            throw ValidationException::withMessages(['issue_date' => 'An invoice is never dated in the future.']);
        }
        $latest = Invoice::query()->whereNotNull('issue_date')->max('issue_date');
        if (is_string($latest) && $requested < substr($latest, 0, 10)) {
            throw ValidationException::withMessages(['issue_date' => sprintf('Invoices are numbered in date order; the last one issued is dated %s.', substr($latest, 0, 10))]);
        }

        return $requested;
    }
}

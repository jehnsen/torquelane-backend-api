<?php

declare(strict_types=1);

namespace App\Actions\Ledger;

use App\Database\Cell;
use App\Domain\Inventory\ItemType;
use App\Domain\Inventory\MoveType;
use App\Domain\Inventory\StockLedger;
use App\Domain\Inventory\StockSource;
use App\Domain\Ledger\AccountBalance;
use App\Domain\Ledger\LedgerEvent;
use App\Domain\Ledger\PostingLine;
use App\Domain\Ledger\Postings\CreditFacts;
use App\Domain\Ledger\Postings\CreditNoteFacts;
use App\Domain\Ledger\Postings\InvoiceFacts;
use App\Domain\Ledger\Postings\InvoiceLineFacts;
use App\Domain\Ledger\Postings\ManualJournalFacts;
use App\Domain\Ledger\Postings\PaymentFacts;
use App\Domain\Ledger\Postings\Postings;
use App\Domain\Ledger\Postings\RefundFacts;
use App\Domain\Ledger\Postings\StockFacts;
use App\Domain\Ledger\Postings\TransferFacts;
use App\Domain\Ledger\Postings\VendorBillFacts;
use App\Domain\Ledger\Postings\VendorBillLineFacts;
use App\Domain\Ledger\Postings\VendorPaymentFacts;
use App\Domain\Ledger\Postings\WithholdingFacts;
use App\Domain\Ledger\Postings\YearCloseFacts;
use App\Domain\Shared\Calendar;
use App\Exceptions\ConflictException;
use App\Models\CreditNote;
use App\Models\DetailingBooking;
use App\Models\Equipment;
use App\Models\EquipmentMaintenanceLog;
use App\Models\FiscalYear;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\ManualJournal;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Refund;
use App\Models\StockCount;
use App\Models\StockMove;
use App\Models\StockTransfer;
use App\Models\VendorBill;
use App\Models\VendorBillLine;
use App\Models\VendorPayment;
use App\Models\WithholdingCertificate;
use App\Models\WorkOrder;
use App\Models\WorkOrderLine;
use LogicException;

/**
 * Where the money and stock actions tell the ledger what happened. Each
 * method runs inside the caller's transaction, builds the event's facts from
 * the document, asks the pure rulebook (`Postings::postingsFor`) for the
 * balanced entry, and has `Ledger` write it. If anything here throws, the
 * document's whole transaction rolls back: a money or stock event never
 * exists without its entry.
 */
final class LedgerPostings
{
    /** @var array<string, string> a document's printed number, by "kind:id" */
    private array $references = [];

    public function __construct(private readonly Ledger $ledger) {}

    public function invoiceIssued(Invoice $invoice): JournalEntry
    {
        $invoice->loadMissing('lines');

        return $this->ledger->post($invoice->organization_id, Postings::postingsFor(new InvoiceFacts(
            $invoice->id,
            (string) $invoice->number,
            $invoice->branch_id,
            $invoice->customer_account_id,
            $invoice->buyer_name,
            (string) $invoice->issue_date?->toDateString(),
            $invoice->seller_vat_registered,
            $invoice->prices_include_vat,
            (string) $invoice->vat_rate_pct,
            $invoice->vatable_sales_cents,
            $invoice->vat_exempt_sales_cents,
            $invoice->zero_rated_sales_cents,
            $invoice->non_vat_sales_cents,
            $invoice->vat_amount_cents,
            $invoice->total_due_cents,
            array_values($invoice->lines->map(function (InvoiceLine $line): InvoiceLineFacts {
                $draft = $line->draft();

                return new InvoiceLineFacts($draft->kind, $draft->taxClass, $draft->grossCents(), $draft->discountCents);
            })->all()),
        )));
    }

    public function invoiceVoided(Invoice $invoice, string $reason, ?string $on = null): JournalEntry
    {
        return $this->ledger->reverse($this->entryOf(LedgerEvent::InvoiceIssued, $invoice->id, "invoice {$invoice->number}"), LedgerEvent::InvoiceVoided, $invoice->id, $reason, $on);
    }

    public function paymentReceived(Payment $payment): JournalEntry
    {
        $payment->loadMissing('customerAccount');

        return $this->ledger->post($payment->organization_id, Postings::postingsFor(new PaymentFacts(
            $payment->id,
            $payment->number,
            $payment->branch_id,
            $payment->customer_account_id,
            $payment->customerAccount->display_name,
            $payment->received_on->toDateString(),
            $payment->amount_cents,
            $payment->method,
        )));
    }

    public function creditApplied(PaymentAllocation $allocation, Payment $payment, Invoice $invoice): JournalEntry
    {
        return $this->ledger->post($allocation->organization_id, Postings::postingsFor(new CreditFacts(
            $allocation->id,
            $payment->number,
            (string) $invoice->number,
            $payment->branch_id,
            $invoice->branch_id,
            $allocation->customer_account_id,
            $allocation->allocated_on->toDateString(),
            $allocation->amount_cents,
        )));
    }

    /**
     * A payment voided: the receipt is undone, and so is every application of it to an invoice.
     */
    public function paymentVoided(Payment $payment, string $reason, ?string $on = null): void
    {
        $this->ledger->reverse($this->entryOf(LedgerEvent::PaymentReceived, $payment->id, "payment {$payment->number}"), LedgerEvent::PaymentVoided, $payment->id, $reason, $on);

        foreach (PaymentAllocation::query()->where('payment_id', $payment->id)->orderBy('id')->get() as $allocation) {
            $this->ledger->reverse($this->entryOf(LedgerEvent::CreditApplied, $allocation->id, "the application of {$payment->number}"), LedgerEvent::CreditReversed, $allocation->id, $reason, $on);
        }
    }

    /* ------------------------------------------------------------ payables (Phase 12) */

    public function vendorBillPosted(VendorBill $bill): JournalEntry
    {
        $bill->loadMissing('lines');

        return $this->ledger->post($bill->organization_id, Postings::postingsFor(new VendorBillFacts(
            $bill->id,
            $bill->number,
            $bill->branch_id,
            $bill->vendor_id,
            $bill->vendor_name,
            $bill->bill_date->toDateString(),
            array_values($bill->lines->map(fn (VendorBillLine $line): VendorBillLineFacts => new VendorBillLineFacts(
                $line->description,
                $line->net_cents,
                $line->received_value_cents === null ? $line->account_id : null,
                $line->received_value_cents,
            ))->all()),
            $bill->input_vat_cents,
            $bill->withholding_cents,
            $bill->payable_cents,
        )));
    }

    public function vendorBillVoided(VendorBill $bill, string $reason, ?string $on = null): JournalEntry
    {
        return $this->ledger->reverse($this->entryOf(LedgerEvent::VendorBillPosted, $bill->id, "bill {$bill->number}"), LedgerEvent::VendorBillVoided, $bill->id, $reason, $on);
    }

    public function vendorPaymentMade(VendorPayment $payment): JournalEntry
    {
        $payment->loadMissing('vendor');

        return $this->ledger->post($payment->organization_id, Postings::postingsFor(new VendorPaymentFacts(
            $payment->id,
            $payment->number,
            $payment->branch_id,
            $payment->vendor_id,
            $payment->vendor->name,
            $payment->paid_on->toDateString(),
            $payment->amount_cents,
            $payment->method,
        )));
    }

    public function vendorPaymentVoided(VendorPayment $payment, string $reason, ?string $on = null): JournalEntry
    {
        return $this->ledger->reverse($this->entryOf(LedgerEvent::VendorPaymentMade, $payment->id, "payment {$payment->number}"), LedgerEvent::VendorPaymentVoided, $payment->id, $reason, $on);
    }

    /* ------------------------------------------------------------ credit notes, refunds, withholding */

    public function creditNoteIssued(CreditNote $note): JournalEntry
    {
        $note->loadMissing(['invoice', 'customerAccount']);

        return $this->ledger->post($note->organization_id, Postings::postingsFor(new CreditNoteFacts(
            $note->id,
            $note->number,
            (string) $note->invoice->number,
            $note->branch_id,
            $note->customer_account_id,
            $note->customerAccount->display_name,
            $note->issued_on->toDateString(),
            $note->netCents(),
            $note->vat_amount_cents,
            $note->applied_cents,
            $note->credit_cents,
        )));
    }

    public function creditNoteVoided(CreditNote $note, string $reason, ?string $on = null): JournalEntry
    {
        return $this->ledger->reverse($this->entryOf(LedgerEvent::CreditNoteIssued, $note->id, "credit note {$note->number}"), LedgerEvent::CreditNoteVoided, $note->id, $reason, $on);
    }

    public function refundPaid(Refund $refund): JournalEntry
    {
        $refund->loadMissing('customerAccount');

        return $this->ledger->post($refund->organization_id, Postings::postingsFor(new RefundFacts(
            $refund->id,
            $refund->number,
            $refund->branch_id,
            $refund->customer_account_id,
            $refund->customerAccount->display_name,
            $refund->refunded_on->toDateString(),
            $refund->amount_cents,
            $refund->method,
        )));
    }

    public function refundVoided(Refund $refund, string $reason, ?string $on = null): JournalEntry
    {
        return $this->ledger->reverse($this->entryOf(LedgerEvent::RefundPaid, $refund->id, "refund {$refund->number}"), LedgerEvent::RefundVoided, $refund->id, $reason, $on);
    }

    public function withholdingReceived(WithholdingCertificate $certificate): JournalEntry
    {
        $certificate->loadMissing(['invoice', 'customerAccount']);

        return $this->ledger->post($certificate->organization_id, Postings::postingsFor(new WithholdingFacts(
            $certificate->id,
            (string) $certificate->invoice->number,
            $certificate->branch_id,
            $certificate->customer_account_id,
            $certificate->customerAccount->display_name,
            $certificate->received_on->toDateString(),
            $certificate->amount_cents,
            $certificate->atc_code,
        )));
    }

    public function withholdingVoided(WithholdingCertificate $certificate, string $reason, ?string $on = null): JournalEntry
    {
        return $this->ledger->reverse($this->entryOf(LedgerEvent::WithholdingReceived, $certificate->id, 'that withholding certificate'), LedgerEvent::WithholdingVoided, $certificate->id, $reason, $on);
    }

    /* ------------------------------------------------------------ vouchers and the year end */

    /**
     * @param  list<PostingLine>  $lines  accounts named by id
     */
    public function manualJournal(ManualJournal $journal, array $lines): JournalEntry
    {
        return $this->ledger->post($journal->organization_id, Postings::postingsFor(new ManualJournalFacts(
            $journal->id,
            $journal->branch_id,
            $journal->entry_date->toDateString(),
            $journal->reference,
            $journal->memo,
            $lines,
        )));
    }

    public function manualJournalReversed(ManualJournal $journal, string $reason): JournalEntry
    {
        return $this->ledger->reverse($this->entryOf(LedgerEvent::ManualJournal, $journal->id, 'that journal voucher'), LedgerEvent::ManualJournalReversed, $journal->id, $reason);
    }

    /**
     * @param  list<AccountBalance>  $balances  the year's revenue and expense balances, by branch
     */
    public function yearClosed(FiscalYear $year, string $branchId, array $balances, string $entryDate): JournalEntry
    {
        return $this->ledger->post($year->organization_id, Postings::postingsFor(new YearCloseFacts($year->id, $year->year, $branchId, $balances, $entryDate)));
    }

    /**
     * A stock move that is not half of a transfer. `$bookDelta` is the change
     * in its balance's book value (on hand × average, rounded), which is what
     * the Inventory account moves by.
     */
    public function stockMove(StockMove $move, int $bookDelta, ?ItemType $itemType = null): JournalEntry
    {
        if ($move->move_type === MoveType::TransferOut || $move->move_type === MoveType::TransferIn) {
            throw new LogicException('A transfer posts as one entry; use stockTransfer().');
        }

        $inbound = $move->quantity->isPositive();
        $event = StockFacts::eventFor($move->move_type, $move->source_type, $inbound);

        return $this->ledger->post($move->organization_id, Postings::postingsFor(new StockFacts(
            $event,
            $move->id,
            $move->branch_id,
            Calendar::toDate($move->occurred_at),
            $itemType ?? $this->itemType($move->item_id),
            $bookDelta,
            $this->counterValue($move, $event),
            $this->stockReference($move),
            (string) ($move->reason ?? $event->label()),
        )));
    }

    /** One line of a transfer: its two moves, one entry. */
    public function stockTransfer(StockMove $out, int $outDelta, StockMove $in, int $inDelta): JournalEntry
    {
        return $this->ledger->post($out->organization_id, Postings::postingsFor(new TransferFacts(
            $out->id,
            $in->id,
            $out->branch_id,
            $in->branch_id,
            Calendar::toDate($out->occurred_at),
            $outDelta,
            $inDelta,
            $this->stockReference($out),
            (string) ($out->reason ?? LedgerEvent::StockTransfer->label()),
        )));
    }

    /**
     * What the move was worth at its own cost: the other side of its entry.
     * A voided receipt gives the goods back at what they were RECEIVED at, so
     * GR/IR clears exactly; the costing difference lands in Inventory
     * Adjustments.
     */
    private function counterValue(StockMove $move, LedgerEvent $event): int
    {
        if ($event === LedgerEvent::StockReceiptReturn && $move->source_type === StockSource::GoodsReceipt && $move->source_id !== null) {
            $line = GoodsReceiptLine::query()->where('goods_receipt_id', $move->source_id)->where('item_id', $move->item_id)->first();
            if ($line !== null) {
                return StockLedger::moveValue($move->quantity->abs(), $line->stock_unit_cost_cents);
            }
        }

        return $move->valueCents();
    }

    private function entryOf(LedgerEvent $event, string $sourceId, string $what): JournalEntry
    {
        return JournalEntry::query()
            ->where('event', $event->value)
            ->where('source_type', $event->sourceType())
            ->where('source_id', $sourceId)
            ->first() ?? throw new ConflictException("The books have no entry for {$what}; run ledger:backfill before voiding it.", ['reason' => 'not_posted']);
    }

    private function itemType(string $itemId): ItemType
    {
        $item = Item::query()->findOrFail($itemId);

        return $item->item_type;
    }

    /** The number of the document that moved the stock, for the journal's Reference column. */
    private function stockReference(StockMove $move): string
    {
        $id = $move->source_id;
        if ($id === null) {
            return '';
        }
        $key = $move->source_type->value.':'.$id;

        return $this->references[$key] ??= match ($move->source_type) {
            StockSource::GoodsReceipt => Cell::string(GoodsReceipt::query()->whereKey($id)->value('reference')),
            StockSource::StockCount => Cell::string(StockCount::query()->whereKey($id)->value('reference')),
            StockSource::StockTransfer => Cell::string(StockTransfer::query()->whereKey($id)->value('reference')),
            StockSource::WorkOrderLine => Cell::string(WorkOrder::query()->whereKey(Cell::string(WorkOrderLine::query()->whereKey($id)->value('work_order_id')))->value('reference')),
            StockSource::DetailingBooking => Cell::string(DetailingBooking::query()->whereKey($id)->value('reference')),
            // A service entry has no number: the tool it was for.
            StockSource::EquipmentMaintenance => Cell::string(Equipment::query()->whereKey(Cell::string(EquipmentMaintenanceLog::query()->whereKey($id)->value('equipment_id')))->value('name')),
            StockSource::Manual => '',
        };
    }
}

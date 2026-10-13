<?php

declare(strict_types=1);

namespace App\Actions\Payables;

use App\Domain\Payables\BillStatus;
use App\Domain\Receivables\Aging;
use App\Domain\Receivables\OpenInvoice;
use App\Domain\Receivables\PaymentStatus;
use App\Domain\Shared\Calendar;
use App\Models\GoodsReceiptLine;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\VendorBillLine;
use App\Models\VendorPayment;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The read side of payables, always within the branches the caller works in
 * (the selected one, or all of theirs). Staff only: payables have no portal
 * side.
 *
 * @phpstan-type BillFilters array{status?: string, vendor_id?: string, branch_id?: string, q?: string}
 * @phpstan-type PaymentFilters array{status?: string, method?: string, vendor_id?: string, branch_id?: string, q?: string}
 */
final class PayablesQueries
{
    public const array BILL_RELATIONS = ['lines.account', 'allocations.payment', 'vendor'];

    public const array PAYMENT_RELATIONS = ['allocations.bill', 'vendor'];

    public function __construct(private readonly TenantManager $tenancy) {}

    /**
     * @return Builder<VendorBill>
     */
    public function bills(): Builder
    {
        $context = $this->tenancy->require();

        return VendorBill::query()->visibleTo($context)->whereIn('branch_id', $context->branchFilter());
    }

    /**
     * @return Builder<VendorPayment>
     */
    public function payments(): Builder
    {
        $context = $this->tenancy->require();

        return VendorPayment::query()->visibleTo($context)->whereIn('branch_id', $context->branchFilter());
    }

    /**
     * Newest first. `status`: one of the statuses, or `open` (unpaid or
     * partially paid) or `overdue` (open and past due).
     *
     * @param  BillFilters  $filters
     * @return LengthAwarePaginator<int, VendorBill>
     */
    public function billPage(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = $this->bills()->with(self::BILL_RELATIONS);
        $open = [BillStatus::Posted->value, BillStatus::PartiallyPaid->value];
        match ($filters['status'] ?? null) {
            null => null,
            'open' => $query->whereIn('status', $open),
            'overdue' => $query->whereIn('status', $open)->where('due_date', '<', Calendar::toDate(CarbonImmutable::now())),
            default => $query->where('status', $filters['status']),
        };
        foreach (['vendor_id', 'branch_id'] as $column) {
            if (isset($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }
        if (($filters['q'] ?? '') !== '') {
            $like = '%'.addcslashes(mb_strtolower((string) $filters['q']), '%_\\').'%';
            $query->where(fn (Builder $w) => $w->whereRaw('lower(number) like ?', [$like])->orWhereRaw('lower(vendor_invoice_no) like ?', [$like])->orWhereRaw('lower(vendor_name) like ?', [$like]));
        }

        return $query->orderByDesc('bill_date')->orderByDesc('number')->orderByDesc('id')->paginate($perPage);
    }

    /**
     * @param  PaymentFilters  $filters
     * @return LengthAwarePaginator<int, VendorPayment>
     */
    public function paymentPage(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = $this->payments()->with(self::PAYMENT_RELATIONS);
        foreach (['status', 'method', 'vendor_id', 'branch_id'] as $column) {
            if (isset($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }
        if (($filters['q'] ?? '') !== '') {
            $like = '%'.addcslashes(mb_strtolower((string) $filters['q']), '%_\\').'%';
            $query->where(fn (Builder $w) => $w->whereRaw('lower(number) like ?', [$like])->orWhereRaw("lower(coalesce(reference_no, '')) like ?", [$like]));
        }

        return $query->orderByDesc('paid_on')->orderByDesc('number')->orderByDesc('id')->paginate($perPage);
    }

    /**
     * Goods received from a vendor that no standing bill has claimed yet: what
     * is sitting in GR/IR, ready to be billed.
     *
     * @return Collection<int, GoodsReceiptLine>
     */
    public function unbilledReceiptLines(?string $vendorId): Collection
    {
        $context = $this->tenancy->require();
        $billed = VendorBillLine::query()
            ->join('vendor_bills', 'vendor_bills.id', '=', 'vendor_bill_lines.vendor_bill_id')
            ->where('vendor_bills.status', '<>', BillStatus::Void->value)
            ->whereNotNull('vendor_bill_lines.goods_receipt_line_id')
            ->select('vendor_bill_lines.goods_receipt_line_id');

        return GoodsReceiptLine::query()
            ->select('goods_receipt_lines.*')
            ->join('goods_receipts', 'goods_receipts.id', '=', 'goods_receipt_lines.goods_receipt_id')
            ->join('shop_purchase_orders', 'shop_purchase_orders.id', '=', 'goods_receipts.shop_purchase_order_id')
            ->where('goods_receipts.status', 'posted')
            ->whereIn('goods_receipts.branch_id', $context->isStaff() ? $context->branchFilter() : [])
            ->when($vendorId !== null, fn (Builder $q) => $q->where('shop_purchase_orders.vendor_id', $vendorId))
            ->whereNotIn('goods_receipt_lines.id', $billed)
            ->with(['receipt.order', 'orderLine', 'item'])
            ->orderBy('goods_receipts.received_on')->orderBy('goods_receipt_lines.id')
            ->get();
    }

    /**
     * Open bills as of a business date: every bill dated on or before it and
     * not voided by then, with what had been paid against it by then
     * (payments dated on or before it and not voided by then).
     *
     * @return list<OpenInvoice> a bill read as an open item: the vendor in the account's place, payable for total
     */
    public function openItems(string $asOf, ?string $vendorId = null): array
    {
        $query = $this->bills()->with(['allocations.payment'])->where('bill_date', '<=', $asOf);
        if ($vendorId !== null) {
            $query->where('vendor_id', $vendorId);
        }

        $items = [];
        foreach ($query->orderBy('due_date')->orderBy('number')->get() as $bill) {
            if ($bill->voided_at !== null && Calendar::toDate($bill->voided_at) <= $asOf) {
                continue;
            }
            $paid = 0;
            foreach ($bill->allocations as $allocation) {
                $payment = $allocation->payment;
                $standing = $payment->status === PaymentStatus::Posted || ($payment->voided_at !== null && Calendar::toDate($payment->voided_at) > $asOf);
                if ($standing && $payment->paid_on->toDateString() <= $asOf) {
                    $paid += $allocation->amount_cents;
                }
            }
            $items[] = new OpenInvoice($bill->id, $bill->number, $bill->vendor_id, $bill->bill_date->toDateString(), $bill->due_date->toDateString(), $bill->payable_cents, $paid);
        }

        return $items;
    }

    /**
     * Payables aging as of a date, per vendor (largest balance first).
     *
     * @return array{as_of: string, vendors: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function aging(string $asOf, ?string $vendorId = null): array
    {
        $report = Aging::report($this->openItems($asOf, $vendorId), $asOf);
        $names = Vendor::query()->whereIn('id', array_keys($report['accounts']))->pluck('name', 'id');

        $rows = [];
        foreach ($report['accounts'] as $id => $buckets) {
            $name = $names->get($id);
            $rows[] = ['vendor_id' => $id, 'vendor_name' => is_string($name) ? $name : ''] + $buckets;
        }
        usort($rows, fn (array $a, array $b): int => [$b['total'], $a['vendor_name']] <=> [$a['total'], $b['vendor_name']]);

        return ['as_of' => $asOf, 'vendors' => $rows, 'totals' => $report['totals']];
    }
}

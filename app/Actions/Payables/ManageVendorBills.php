<?php

declare(strict_types=1);

namespace App\Actions\Payables;

use App\Actions\Audit\AuditTrail;
use App\Actions\Ledger\LedgerPostings;
use App\Actions\Ledger\PostingRules;
use App\Actions\Numbering\DocumentNumbers;
use App\Database\Cell;
use App\Domain\Inventory\ReceiptStatus;
use App\Domain\Inventory\StockLedger;
use App\Domain\Inventory\TaxClass;
use App\Domain\Invoicing\VatTreatment;
use App\Domain\Ledger\AccountType;
use App\Domain\Ledger\RuleKey;
use App\Domain\Numbering\DocumentType;
use App\Domain\Payables\BillLineDraft;
use App\Domain\Payables\BillLineKind;
use App\Domain\Payables\BillStatus;
use App\Domain\Payables\Payables;
use App\Domain\Shared\Calendar;
use App\Domain\Tax\Atc;
use App\Exceptions\ConflictException;
use App\Exceptions\InvalidTransitionException;
use App\Models\Account;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Item;
use App\Models\ShopPurchaseOrder;
use App\Models\ShopPurchaseOrderLine;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\VendorBillLine;
use App\Models\VendorPaymentAllocation;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What vendors bill the shop (accounts payable).
 *
 * record: one bill from one vendor to one branch, numbered from the
 * `vendor_bill` series in this transaction (R8) and posted to the books
 * there and then (R3). A line either bills goods already received against a
 * shop purchase order (a goods receipt line, billed whole and once) or
 * charges an expense straight to an account. Goods that went on the shelf
 * clear what their receipt left in GR/IR; what the vendor charged above or
 * below that is a purchase price variance, never a change to stock. The
 * totals (input VAT, withholding, what is payable) are worked out here from
 * the lines; none a client sends is read.
 *
 * A bill may be paid as it is recorded (`payment`): the expense voucher of a
 * petty-cash purchase is a bill and its cash-out voucher in one transaction.
 *
 * void: R7's reversal. Only a bill with nothing paid (void its payments
 * first). It keeps its number; its receipt lines may be billed again.
 *
 * Locks: the vendor, then the goods receipts (id order), then the bill.
 *
 * @phpstan-type BillLineInput array{kind: string, goods_receipt_line_id?: string|null, account_id?: string|null, description?: string|null, quantity?: string|null, unit_cost_cents?: int|null, tax_class?: string|null}
 * @phpstan-type BillInput array{branch_id: string, vendor_invoice_no: string, bill_date?: string|null, due_date?: string|null, prices_include_vat?: bool|null, vat_rate_pct?: string|null, withholding?: array{atc_code: string, rate_pct: string}|false|null, notes?: string|null, payment?: array{method: string, reference_no?: string|null, paid_on?: string|null}|null, lines: list<BillLineInput>}
 */
final class ManageVendorBills
{
    /** The VAT rate a bill carries unless it says otherwise. */
    public const string DEFAULT_VAT_RATE = '12';

    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly DocumentNumbers $numbers,
        private readonly LedgerPostings $postings,
        private readonly PostingRules $rules,
        private readonly AuditTrail $audit,
        private readonly PayVendors $payments,
    ) {}

    /**
     * @param  BillInput  $data  validated
     */
    public function record(Vendor $vendor, array $data): VendorBill
    {
        return DB::transaction(function () use ($vendor, $data): VendorBill {
            // The vendor row serialises its bills: two requests cannot record the same invoice twice.
            $vendor = Vendor::query()->lockForUpdate()->findOrFail($vendor->id);
            if (! $vendor->is_active) {
                throw new ConflictException("{$vendor->name} is inactive; reactivate the vendor to record its bills.", ['reason' => 'vendor_inactive']);
            }

            $now = CarbonImmutable::now();
            $today = Calendar::toDate($now);
            $billDate = $data['bill_date'] ?? $today;
            if ($billDate > $today) {
                throw ValidationException::withMessages(['bill_date' => 'A bill is never dated in the future.']);
            }
            $dueDate = $data['due_date'] ?? Payables::dueDate($billDate, $vendor->payment_terms_days);
            if ($dueDate < $billDate) {
                throw ValidationException::withMessages(['due_date' => 'A bill is not due before it is dated.']);
            }

            $invoiceNo = trim($data['vendor_invoice_no']);
            $taken = VendorBill::query()
                ->where('vendor_id', $vendor->id)
                ->whereRaw('lower(vendor_invoice_no) = ?', [mb_strtolower($invoiceNo)])
                ->where('status', '<>', BillStatus::Void->value)
                ->value('number');
            if (is_string($taken)) {
                throw ValidationException::withMessages(['vendor_invoice_no' => "{$vendor->name}'s invoice {$invoiceNo} is already recorded as {$taken}."]);
            }

            $drafts = $this->drafts($vendor, $data['branch_id'], $data['lines']);
            $vat = new VatTreatment($vendor->is_vat_registered, (bool) ($data['prices_include_vat'] ?? false), $data['vat_rate_pct'] ?? self::DEFAULT_VAT_RATE);
            $withholding = $this->withholding($vendor, $data['withholding'] ?? null);
            $totals = Payables::totals($drafts, $vat, $withholding['rate_pct'] ?? null);
            if ($totals->totalCents <= 0) {
                throw ValidationException::withMessages(['lines' => 'A bill is for more than nothing.']);
            }

            $actor = $this->actor();
            $bill = new VendorBill;
            $bill->forceFill([
                'branch_id' => $data['branch_id'],
                'vendor_id' => $vendor->id,
                'number' => $this->numbers->issue($vendor->organization_id, null, DocumentType::VendorBill, $now)->formatted,
                'status' => BillStatus::settled(0, $totals->payableCents()),
                'vendor_invoice_no' => $invoiceNo,
                'bill_date' => $billDate,
                'due_date' => $dueDate,
                'vendor_name' => $vendor->registered_name ?? $vendor->name,
                'vendor_tin' => $vendor->tin,
                'vendor_address' => $vendor->address,
                'vendor_vat_registered' => $vat->vatRegistered,
                'prices_include_vat' => $vat->pricesIncludeVat,
                'vat_rate_pct' => $vat->vatRatePct,
                'vatable_purchases_cents' => $totals->vatablePurchasesCents,
                'vat_exempt_purchases_cents' => $totals->vatExemptPurchasesCents,
                'zero_rated_purchases_cents' => $totals->zeroRatedPurchasesCents,
                'non_vat_purchases_cents' => $totals->nonVatPurchasesCents,
                'input_vat_cents' => $totals->inputVatCents,
                'total_cents' => $totals->totalCents,
                'atc_code' => $withholding['atc_code'] ?? null,
                'withholding_rate_pct' => $withholding['rate_pct'] ?? null,
                'withholding_base_cents' => $totals->withholdingBaseCents,
                'withholding_cents' => $totals->withholdingCents,
                'payable_cents' => $totals->payableCents(),
                'paid_cents' => 0,
                'notes' => trim((string) ($data['notes'] ?? '')),
                'created_by' => $actor->id,
                'created_by_name' => $actor->name,
            ])->save();

            foreach ($drafts as $position => $draft) {
                $line = new VendorBillLine;
                $line->forceFill([
                    'vendor_bill_id' => $bill->id,
                    'position' => $position,
                    'kind' => $draft->kind,
                    'description' => $draft->description,
                    'goods_receipt_line_id' => $draft->goodsReceiptLineId,
                    'account_id' => $draft->accountId,
                    'quantity' => $draft->quantity,
                    'unit_cost_cents' => $draft->unitCostCents,
                    'tax_class' => $draft->taxClass,
                    'line_total_cents' => $draft->totalCents(),
                    'net_cents' => $totals->netByLine[$position],
                    'received_value_cents' => $draft->receivedValueCents,
                ])->save();
            }

            $bill->load('lines');
            $this->postings->vendorBillPosted($bill);
            $this->audit->record($bill, 'recorded', null, self::snapshot($bill));

            // Paid on the spot (petty cash, a card at the counter): the cash-out voucher in the same transaction.
            if (isset($data['payment']) && $bill->payable_cents > 0) {
                $this->payments->record($vendor, [
                    'branch_id' => $bill->branch_id,
                    'method' => $data['payment']['method'],
                    'reference_no' => $data['payment']['reference_no'] ?? null,
                    'paid_on' => $data['payment']['paid_on'] ?? null,
                    'allocations' => [['vendor_bill_id' => $bill->id, 'amount_cents' => $bill->payable_cents]],
                ]);
                $bill->refresh()->load('lines');
            }

            return $bill;
        });
    }

    public function void(VendorBill $bill, string $reason): VendorBill
    {
        return DB::transaction(function () use ($bill, $reason): VendorBill {
            $locked = VendorBill::query()->lockForUpdate()->findOrFail($bill->id);
            if ($locked->status === BillStatus::Void) {
                throw new InvalidTransitionException("Bill {$locked->number} is already void.");
            }
            $standing = VendorPaymentAllocation::query()
                ->join('vendor_payments', 'vendor_payments.id', '=', 'vendor_payment_allocations.vendor_payment_id')
                ->where('vendor_payment_allocations.vendor_bill_id', $locked->id)
                ->where('vendor_payments.status', 'posted')
                ->exists();
            if ($locked->paid_cents > 0 || $standing) {
                throw new InvalidTransitionException("Bill {$locked->number} has payments against it; void them first.");
            }

            $before = self::snapshot($locked);
            $locked->forceFill([
                'status' => BillStatus::Void,
                'voided_at' => CarbonImmutable::now(),
                'voided_by_name' => $this->actor()->name,
                'void_reason' => $reason,
            ])->save();
            $this->postings->vendorBillVoided($locked, $reason);
            $this->audit->record($locked, 'voided', $before, self::snapshot($locked));

            return $locked->load('lines');
        });
    }

    /**
     * @return array<string, mixed>
     */
    public static function snapshot(VendorBill $bill): array
    {
        return AuditTrail::snapshot($bill) + [
            'lines' => array_values($bill->lines()->get()->map(fn (VendorBillLine $line): array => AuditTrail::snapshot($line))->all()),
        ];
    }

    /**
     * Each requested line, checked against the stored state it depends on.
     *
     * @param  list<BillLineInput>  $lines
     * @return list<BillLineDraft>
     */
    private function drafts(Vendor $vendor, string $branchId, array $lines): array
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'A bill has at least one line.']);
        }

        $receiptLineIds = [];
        foreach ($lines as $line) {
            if ($line['kind'] === BillLineKind::Goods->value && isset($line['goods_receipt_line_id'])) {
                $receiptLineIds[] = $line['goods_receipt_line_id'];
            }
        }
        $receiptLines = GoodsReceiptLine::query()->whereIn('id', $receiptLineIds)->get()->keyBy('id');
        $receiptIds = array_values(array_unique(array_map(fn (GoodsReceiptLine $l): string => $l->goods_receipt_id, array_values($receiptLines->all()))));
        sort($receiptIds);
        // Locked, so a receipt cannot be voided (nor billed twice) while this bill is being written.
        $receipts = GoodsReceipt::query()->whereIn('id', $receiptIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $orders = ShopPurchaseOrder::query()->whereIn('id', array_values(array_unique(array_map(fn (GoodsReceipt $r): string => $r->shop_purchase_order_id, array_values($receipts->all())))))->get()->keyBy('id');
        $orderLines = ShopPurchaseOrderLine::query()->whereIn('id', array_map(fn (GoodsReceiptLine $l): string => $l->shop_purchase_order_line_id, array_values($receiptLines->all())))->get()->keyBy('id');
        $items = Item::query()->whereIn('id', array_values(array_filter(array_map(fn (GoodsReceiptLine $l): ?string => $l->item_id, array_values($receiptLines->all())))))->get()->keyBy('id');
        $billed = $receiptLineIds === [] ? [] : VendorBillLine::query()
            ->join('vendor_bills', 'vendor_bills.id', '=', 'vendor_bill_lines.vendor_bill_id')
            ->whereIn('vendor_bill_lines.goods_receipt_line_id', $receiptLineIds)
            ->where('vendor_bills.status', '<>', BillStatus::Void->value)
            ->pluck('vendor_bills.number', 'vendor_bill_lines.goods_receipt_line_id')->all();

        $drafts = [];
        $seen = [];
        foreach ($lines as $position => $line) {
            $field = "lines.{$position}";
            $taxClass = isset($line['tax_class']) ? TaxClass::from($line['tax_class']) : null;

            if ($line['kind'] === BillLineKind::Expense->value) {
                $description = trim((string) ($line['description'] ?? ''));
                if ($description === '') {
                    throw ValidationException::withMessages(["{$field}.description" => 'Say what the expense was for.']);
                }
                if (! isset($line['unit_cost_cents'])) {
                    throw ValidationException::withMessages(["{$field}.unit_cost_cents" => 'An expense line needs its amount.']);
                }
                $drafts[] = new BillLineDraft(
                    BillLineKind::Expense,
                    $description,
                    $line['quantity'] ?? '1',
                    $line['unit_cost_cents'],
                    $taxClass ?? TaxClass::Vatable,
                    $this->expenseAccount($vendor->organization_id, $line['account_id'] ?? null, "{$field}.account_id")->id,
                );

                continue;
            }

            $receiptLineId = $line['goods_receipt_line_id'] ?? null;
            $receiptLine = $receiptLineId === null ? null : $receiptLines->get($receiptLineId);
            $key = "{$field}.goods_receipt_line_id";
            if ($receiptLineId === null || ! $receiptLine instanceof GoodsReceiptLine) {
                throw ValidationException::withMessages([$key => 'Name the goods receipt line being billed.']);
            }
            if (isset($seen[$receiptLineId])) {
                throw ValidationException::withMessages([$key => 'A receipt line is billed once.']);
            }
            $seen[$receiptLineId] = true;

            $receipt = $receipts->get($receiptLine->goods_receipt_id);
            $order = $receipt instanceof GoodsReceipt ? $orders->get($receipt->shop_purchase_order_id) : null;
            if (! $receipt instanceof GoodsReceipt || ! $order instanceof ShopPurchaseOrder || $order->vendor_id !== $vendor->id) {
                throw ValidationException::withMessages([$key => "That receipt was not against one of {$vendor->name}'s purchase orders."]);
            }
            if ($receipt->branch_id !== $branchId) {
                throw ValidationException::withMessages([$key => "Receipt {$receipt->reference} belongs to another branch; bill it there."]);
            }
            if ($receipt->status !== ReceiptStatus::Posted) {
                throw ValidationException::withMessages([$key => "Receipt {$receipt->reference} is void; there is nothing of it to bill."]);
            }
            if (isset($billed[$receiptLineId])) {
                throw ValidationException::withMessages([$key => 'That receipt line is already on bill '.Cell::string($billed[$receiptLineId]).'.']);
            }

            $item = $receiptLine->item_id === null ? null : $items->get($receiptLine->item_id);
            $orderLine = $orderLines->get($receiptLine->shop_purchase_order_line_id);
            $description = trim((string) ($line['description'] ?? '')) ?: ($orderLine instanceof ShopPurchaseOrderLine ? $orderLine->description : "Goods on {$receipt->reference}");
            // On the shelf: clear what the receipt credited GR/IR with. Bought for one job: straight to cost of sales.
            $onShelf = $receiptLine->item_id !== null;
            $drafts[] = new BillLineDraft(
                BillLineKind::Goods,
                "{$description} ({$receipt->reference})",
                (string) $receiptLine->quantity,
                $line['unit_cost_cents'] ?? $receiptLine->unit_cost_cents,
                $taxClass ?? ($item instanceof Item ? $item->tax_class : TaxClass::Vatable),
                $onShelf ? null : $this->rules->accountId($vendor->organization_id, RuleKey::CogsParts),
                $receiptLine->id,
                $onShelf ? StockLedger::moveValue($receiptLine->stock_quantity, $receiptLine->stock_unit_cost_cents) : null,
            );
        }

        return $drafts;
    }

    /** The account an expense line debits: an active expense or asset account that no subledger controls. */
    private function expenseAccount(string $organizationId, ?string $accountId, string $field): Account
    {
        $account = $accountId === null ? null : Account::query()->find($accountId);
        if (! $account instanceof Account || $account->organization_id !== $organizationId) {
            throw ValidationException::withMessages([$field => 'Pick the account this expense is charged to.']);
        }
        if (! $account->is_active) {
            throw ValidationException::withMessages([$field => "{$account->name} is inactive; it takes no new postings."]);
        }
        if ($account->type !== AccountType::Expense && $account->type !== AccountType::Asset) {
            throw ValidationException::withMessages([$field => "A bill is charged to an expense or an asset account; {$account->name} is {$account->type->value}."]);
        }
        if (in_array($account->id, $this->rules->controlAccountIds($account->organization_id), true)) {
            throw ValidationException::withMessages([$field => "{$account->name} is a control account; only its own documents post to it."]);
        }

        return $account;
    }

    /**
     * What is withheld from this bill: what the request says, else the vendor's usual code and rate, else nothing.
     *
     * @param  array{atc_code: string, rate_pct: string}|false|null  $requested  false = withhold nothing, whatever the vendor's default
     * @return array{atc_code: string, rate_pct: string}|null
     */
    private function withholding(Vendor $vendor, array|false|null $requested): ?array
    {
        if ($requested === false) {
            return null;
        }
        if ($requested === null) {
            return $vendor->default_atc_code === null || $vendor->default_withholding_rate_pct === null
                ? null
                : ['atc_code' => $vendor->default_atc_code, 'rate_pct' => (string) $vendor->default_withholding_rate_pct];
        }
        $code = strtoupper(trim($requested['atc_code']));
        if (! Atc::isValid($code)) {
            throw ValidationException::withMessages(['withholding.atc_code' => 'An ATC reads like WC158 or WI160.']);
        }

        return ['atc_code' => $code, 'rate_pct' => $requested['rate_pct']];
    }

    private function actor(): User
    {
        return User::query()->findOrFail($this->tenancy->require()->userId);
    }
}

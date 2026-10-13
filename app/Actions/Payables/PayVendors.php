<?php

declare(strict_types=1);

namespace App\Actions\Payables;

use App\Actions\Audit\AuditTrail;
use App\Actions\Ledger\LedgerPostings;
use App\Actions\Numbering\DocumentNumbers;
use App\Domain\Numbering\DocumentType;
use App\Domain\Payables\BillStatus;
use App\Domain\Receivables\Allocation;
use App\Domain\Receivables\OpenInvoice;
use App\Domain\Receivables\PaymentMethod;
use App\Domain\Receivables\PaymentStatus;
use App\Domain\Shared\Calendar;
use App\Domain\Shared\WebFormat;
use App\Exceptions\InvalidTransitionException;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\VendorPayment;
use App\Models\VendorPaymentAllocation;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Money out to a vendor: the cash-out voucher. Numbered from the
 * `vendor_payment` series when recorded (R8), then a posted document (R7):
 * never edited, only voided.
 *
 * A payment settles bills of ONE vendor at ONE branch and is allocated in
 * full as it is recorded: as the request says, or to the oldest due first.
 * There are no advances to vendors: money that pays no bill is refused.
 * Every bill it touches has its paid figure and status recomputed in the same
 * transaction (BillSettlement).
 *
 * Locks: the vendor's open bills first (id order), then the payment.
 *
 * @phpstan-type PaymentInput array{branch_id: string, method: string, reference_no?: string|null, amount_cents?: int|null, paid_on?: string|null, notes?: string|null, allocations?: list<array{vendor_bill_id: string, amount_cents: int}>|null}
 */
final class PayVendors
{
    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly BillSettlement $settlement,
        private readonly DocumentNumbers $numbers,
        private readonly LedgerPostings $postings,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @param  PaymentInput  $data  validated
     */
    public function record(Vendor $vendor, array $data): VendorPayment
    {
        $method = PaymentMethod::from($data['method']);
        $reference = trim((string) ($data['reference_no'] ?? ''));
        if ($method->needsReference() && $reference === '') {
            throw ValidationException::withMessages(['reference_no' => "A {$method->label()} payment needs its reference number."]);
        }

        return DB::transaction(function () use ($vendor, $data, $method, $reference): VendorPayment {
            $now = CarbonImmutable::now();
            $today = Calendar::toDate($now);
            $paidOn = $data['paid_on'] ?? $today;
            if ($paidOn > $today) {
                throw ValidationException::withMessages(['paid_on' => 'A payment is never dated in the future.']);
            }

            $open = $this->lockOpenBills($vendor->id, $data['branch_id']);
            $plan = $this->plan($open, $data['allocations'] ?? null, $data['amount_cents'] ?? null);
            foreach (array_keys($plan) as $billId) {
                if ($paidOn < $open[$billId]->bill_date->toDateString()) {
                    throw ValidationException::withMessages(['paid_on' => "A payment is not dated before bill {$open[$billId]->number} it pays."]);
                }
            }
            $actor = $this->actor();

            $payment = new VendorPayment;
            $payment->forceFill([
                'branch_id' => $data['branch_id'],
                'vendor_id' => $vendor->id,
                'number' => $this->numbers->issue($vendor->organization_id, null, DocumentType::VendorPayment, $now)->formatted,
                'status' => PaymentStatus::Posted,
                'method' => $method,
                'reference_no' => $reference === '' ? null : $reference,
                'amount_cents' => array_sum($plan),
                'paid_on' => $paidOn,
                'paid_by' => $actor->id,
                'paid_by_name' => $actor->name,
                'notes' => trim((string) ($data['notes'] ?? '')),
            ])->save();

            foreach ($plan as $billId => $amount) {
                $allocation = new VendorPaymentAllocation;
                $allocation->forceFill([
                    'vendor_payment_id' => $payment->id,
                    'vendor_bill_id' => $billId,
                    'vendor_id' => $vendor->id,
                    'amount_cents' => $amount,
                ])->save();
                $this->settlement->refresh($open[$billId]);
            }

            $payment->setRelation('vendor', $vendor);
            $this->postings->vendorPaymentMade($payment);
            $this->audit->record($payment, 'paid', null, self::snapshot($payment));

            return $payment->load('allocations.bill');
        });
    }

    /** Void a payment: it keeps its number; every bill it paid is owed again. */
    public function void(VendorPayment $payment, string $reason): VendorPayment
    {
        return DB::transaction(function () use ($payment, $reason): VendorPayment {
            $billIds = array_values(VendorPaymentAllocation::query()->where('vendor_payment_id', $payment->id)->pluck('vendor_bill_id')->map(fn (mixed $id): string => is_string($id) ? $id : '')->unique()->all());
            sort($billIds);
            $bills = VendorBill::query()->whereIn('id', $billIds)->orderBy('id')->lockForUpdate()->get();
            $locked = VendorPayment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($locked->status !== PaymentStatus::Posted) {
                throw new InvalidTransitionException("Payment {$locked->number} is already void.");
            }
            $before = self::snapshot($locked);

            $locked->forceFill([
                'status' => PaymentStatus::Void,
                'voided_at' => CarbonImmutable::now(),
                'voided_by_name' => $this->actor()->name,
                'void_reason' => $reason,
            ])->save();
            foreach ($bills as $bill) {
                $this->settlement->refresh($bill);
            }
            $this->postings->vendorPaymentVoided($locked, $reason);
            $this->audit->record($locked, 'voided', $before, self::snapshot($locked));

            return $locked->load('allocations.bill');
        });
    }

    /**
     * @return array<string, mixed>
     */
    public static function snapshot(VendorPayment $payment): array
    {
        return AuditTrail::snapshot($payment) + [
            'allocations' => array_values($payment->allocations()->get()->map(fn (VendorPaymentAllocation $a): array => AuditTrail::snapshot($a))->all()),
        ];
    }

    /**
     * Which bills the payment settles, and for how much.
     *
     * @param  array<string, VendorBill>  $open  locked, by id
     * @param  list<array{vendor_bill_id: string, amount_cents: int}>|null  $requested
     * @return array<string, int> bill id → centavos
     */
    private function plan(array $open, ?array $requested, ?int $amountCents): array
    {
        if ($requested === null) {
            if ($amountCents === null) {
                throw ValidationException::withMessages(['amount_cents' => 'Say how much was paid, or which bills.']);
            }
            // Oldest due first; the receivables rule reads a bill as an open item (payable, paid).
            $items = array_values(array_map(
                fn (VendorBill $bill): OpenInvoice => new OpenInvoice($bill->id, $bill->number, $bill->vendor_id, $bill->bill_date->toDateString(), $bill->due_date->toDateString(), $bill->payable_cents, $bill->paid_cents),
                $open,
            ));
            $plan = Allocation::oldestFirst($amountCents, $items);
            if (array_sum($plan) !== $amountCents) {
                throw ValidationException::withMessages(['amount_cents' => sprintf('This vendor is owed %s at this branch; a payment settles bills and nothing more.', WebFormat::pesos(array_sum(array_map(fn (VendorBill $bill): int => $bill->balanceCents(), $open))))]);
            }

            return $plan;
        }

        $plan = [];
        foreach ($requested as $index => $row) {
            $bill = $open[$row['vendor_bill_id']] ?? null;
            if (isset($plan[$row['vendor_bill_id']])) {
                throw ValidationException::withMessages(["allocations.{$index}" => 'Each bill is paid once per payment.']);
            }
            if (! $bill instanceof VendorBill) {
                throw ValidationException::withMessages(["allocations.{$index}" => 'That bill is not open for this vendor at this branch.']);
            }
            if ($row['amount_cents'] > $bill->balanceCents()) {
                throw ValidationException::withMessages(["allocations.{$index}" => sprintf('Bill %s has only %s outstanding.', $bill->number, WebFormat::pesos($bill->balanceCents()))]);
            }
            $plan[$bill->id] = $row['amount_cents'];
        }
        if ($plan === []) {
            throw ValidationException::withMessages(['allocations' => 'Name at least one bill to pay.']);
        }
        if ($amountCents !== null && $amountCents !== array_sum($plan)) {
            throw ValidationException::withMessages(['amount_cents' => sprintf('The bills named add up to %s, not %s.', WebFormat::pesos(array_sum($plan)), WebFormat::pesos($amountCents))]);
        }

        return $plan;
    }

    /**
     * The vendor's open bills at the branch, locked in id order.
     *
     * @return array<string, VendorBill>
     */
    private function lockOpenBills(string $vendorId, string $branchId): array
    {
        $locked = [];
        $bills = VendorBill::query()
            ->where('vendor_id', $vendorId)
            ->where('branch_id', $branchId)
            ->whereIn('status', [BillStatus::Posted->value, BillStatus::PartiallyPaid->value])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        foreach ($bills as $bill) {
            $locked[$bill->id] = $bill;
        }

        return $locked;
    }

    private function actor(): User
    {
        return User::query()->findOrFail($this->tenancy->require()->userId);
    }
}

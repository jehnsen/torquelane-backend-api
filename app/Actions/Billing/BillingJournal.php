<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Actions\Audit\AuditTrail;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceWorkOrder;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * What every billing action shares: the row locks (always invoices before
 * payments, each set in id order, so two documents never deadlock), the
 * acting user, and the audit row written in the action's own transaction.
 */
final class BillingJournal
{
    private ?User $actor = null;

    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly AuditTrail $audit,
    ) {}

    public function lockInvoice(Invoice $invoice): Invoice
    {
        self::inTransaction();

        return Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, Invoice> by id, locked in id order
     */
    public function lockInvoices(array $ids): array
    {
        self::inTransaction();
        $ids = array_values(array_unique($ids));
        sort($ids);
        $locked = [];
        foreach (Invoice::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get() as $invoice) {
            $locked[$invoice->id] = $invoice;
        }

        return $locked;
    }

    public function lockPayment(Payment $payment): Payment
    {
        self::inTransaction();

        return Payment::query()->lockForUpdate()->findOrFail($payment->id);
    }

    public function actor(): User
    {
        $userId = $this->tenancy->require()->userId;
        if ($this->actor === null || $this->actor->id !== $userId) {
            $this->actor = User::query()->findOrFail($userId);
        }

        return $this->actor;
    }

    /**
     * @param  array<string, mixed>|null  $before
     */
    public function auditInvoice(Invoice $invoice, string $action, ?array $before): void
    {
        $this->audit->record($invoice, $action, $before, self::invoiceSnapshot($invoice->refresh()));
    }

    /**
     * @param  array<string, mixed>|null  $before
     */
    public function auditPayment(Payment $payment, string $action, ?array $before): void
    {
        $this->audit->record($payment, $action, $before, self::paymentSnapshot($payment->refresh()));
    }

    /**
     * @return array<string, mixed>
     */
    public static function invoiceSnapshot(Invoice $invoice): array
    {
        return AuditTrail::snapshot($invoice) + [
            'lines' => array_values($invoice->lines()->get()->map(fn (InvoiceLine $line): array => AuditTrail::snapshot($line))->all()),
            'work_order_ids' => array_values($invoice->workOrderLinks()->get()->map(fn (InvoiceWorkOrder $link): string => $link->work_order_id)->all()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function paymentSnapshot(Payment $payment): array
    {
        return AuditTrail::snapshot($payment) + [
            'allocations' => array_values($payment->allocations()->get()->map(fn (PaymentAllocation $a): array => AuditTrail::snapshot($a))->all()),
        ];
    }

    private static function inTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Billing documents are locked inside the action\'s transaction.');
        }
    }
}

<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Actions\Billing\ManageInvoices;
use App\Actions\Billing\RecordPayment;
use App\Domain\Shared\Calendar;
use App\Domain\WorkOrders\WorkOrderStatus;
use App\Models\CustomerAccount;
use App\Models\User;
use App\Models\WorkOrder;
use App\Tenancy\TenantContextResolver;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Phase 7's demo data: receivables, built through the real Actions as the
 * owner, so numbers, totals, allocations and audit rows are the real thing.
 * Dates are relative to today, so the aging report always has something in
 * more than one bucket:
 *
 *  - Actimed: its three oldest closed jobs on one invoice issued 75 days ago
 *    (30-day terms, so 45 days past due), part-paid by bank transfer 30 days
 *    ago; its next two on an invoice issued 20 days ago (current);
 *  - Northwind: its two oldest on an invoice issued 50 days ago (45-day terms:
 *    5 days past due), unpaid, against a ₱5,000 credit limit it is now over
 *    (new work for it shows the credit warning);
 *  - Sagrada: a draft for its oldest closed job, not yet issued.
 *
 * Every other closed job stays in the billing queue. Nothing is fully paid,
 * so no job's `collected_at` changes (the shop's golden revenue sweeps read it).
 *
 * Ids: `invoice:actimed-overdue`, `invoice:actimed-current`,
 * `invoice:northwind`, `invoice:sagrada-draft`, `payment:actimed-partial`.
 */
final class BillingSeed
{
    /**
     * @param  array<string, string>  $ids  source id → ULID, extended in place
     */
    public static function run(array &$ids): void
    {
        $owner = User::query()->findOrFail($ids['owner@mekanikomore.ph'] ?? throw new RuntimeException('BillingSeed needs the demo owner.'));
        $context = app(TenantContextResolver::class)->resolve($owner, null)->context ?? throw new RuntimeException('The demo owner has no tenant context.');

        app(TenantManager::class)->actingAs($context, function () use (&$ids): void {
            self::seed($ids);
        });
    }

    /**
     * @param  array<string, string>  $ids
     */
    private static function seed(array &$ids): void
    {
        $invoices = app(ManageInvoices::class);
        $payments = app(RecordPayment::class);
        $today = Calendar::parseDate(Calendar::toDate(CarbonImmutable::now()));
        $daysAgo = fn (int $days): string => Calendar::toDate(Calendar::addDays($today, -$days));

        $actimed = self::ready($ids['fc-actimed'], 5);
        $northwind = self::ready($ids['fc-northwind'], 2);
        $sagrada = self::ready($ids['fc-sagrada'], 1);

        CustomerAccount::query()->whereKey($ids['fc-northwind'])->update(['credit_limit_cents' => 500_000]);

        $overdue = $invoices->fromWorkOrders(array_slice($actimed, 0, 3), 'Fleet maintenance, consolidated.');
        $invoices->issue($overdue, $daysAgo(75));
        $ids['invoice:actimed-overdue'] = $overdue->id;

        $north = $invoices->fromWorkOrders($northwind);
        $invoices->issue($north, $daysAgo(50));
        $ids['invoice:northwind'] = $north->id;

        $current = $invoices->fromWorkOrders(array_slice($actimed, 3, 2));
        $invoices->issue($current, $daysAgo(20));
        $ids['invoice:actimed-current'] = $current->id;

        $overdue->refresh();
        $paid = $payments->record(CustomerAccount::query()->findOrFail($ids['fc-actimed']), [
            'branch_id' => $overdue->branch_id,
            'method' => 'bank_transfer',
            'reference_no' => 'BDO-0930-55812',
            'amount_cents' => intdiv($overdue->total_due_cents * 2, 5),
            'received_on' => $daysAgo(30),
            'notes' => 'Partial settlement per fleet AP.',
            'allocations' => [['invoice_id' => $overdue->id, 'amount_cents' => intdiv($overdue->total_due_cents * 2, 5)]],
        ]);
        $ids['payment:actimed-partial'] = $paid->id;

        $ids['invoice:sagrada-draft'] = $invoices->fromWorkOrders($sagrada)->id;
    }

    /**
     * An account's oldest closed jobs that are neither settled nor invoiced.
     *
     * @return list<WorkOrder>
     */
    private static function ready(string $accountId, int $count): array
    {
        return array_values(WorkOrder::query()
            ->where('customer_account_id', $accountId)
            ->where('status', WorkOrderStatus::Closed->value)
            ->whereNull('collected_at')
            ->whereNotNull('branch_id')
            ->orderBy('completed_on')
            ->orderBy('id')
            ->limit($count)
            ->get()
            ->all());
    }
}

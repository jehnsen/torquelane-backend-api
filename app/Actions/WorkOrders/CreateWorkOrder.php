<?php

declare(strict_types=1);

namespace App\Actions\WorkOrders;

use App\Domain\WorkOrders\WorkOrderReference;
use App\Domain\WorkOrders\WorkOrderStatus;
use App\Exceptions\ConflictException;
use App\Models\CustomerAccount;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * createDraft: a new order in `draft`, unnumbered (a draft that never leaves
 * the shop burns no number). Lines are priced here; the order is stamped with
 * the vehicle's current owner and keeps it.
 */
final class CreateWorkOrder
{
    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly WorkOrderJournal $journal,
        private readonly LineWriter $lines,
        private readonly ApprovalSettingsResolver $settings,
        private readonly WorkOrderBranch $branches,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated header fields (SaveWorkOrderRequest)
     * @param  list<array<string, mixed>>  $lines  quantities and rates; priced here
     */
    public function handle(Vehicle $vehicle, array $data, array $lines = []): WorkOrder
    {
        $context = $this->tenancy->require();

        return DB::transaction(function () use ($context, $vehicle, $data, $lines): WorkOrder {
            $locked = Vehicle::query()->lockForUpdate()->findOrFail($vehicle->id);
            if ($locked->isArchived()) {
                throw new ConflictException('This vehicle is archived; it takes no new work.');
            }
            $account = CustomerAccount::query()->findOrFail($locked->customer_account_id);
            $branchId = $this->branches->forNewWork(isset($data['branch_id']) && is_string($data['branch_id']) ? $data['branch_id'] : null);
            $now = CarbonImmutable::now();

            $order = new WorkOrder;
            $order->forceFill([
                'branch_id' => $branchId,
                'customer_account_id' => $account->id,
                'vehicle_id' => $locked->id,
                'reference' => WorkOrderReference::DRAFT,
                'title' => $data['title'],
                'type' => $data['type'],
                'status' => WorkOrderStatus::Draft,
                'priority' => $data['priority'] ?? 'medium',
                'opened_on' => $now->setTimezone('Asia/Manila')->toDateString(),
                'scheduled_for' => $data['scheduled_for'] ?? null,
                'scheduled_time' => $data['scheduled_time'] ?? null,
                'vendor' => $data['vendor'] ?? '',
                'odometer_at_intake' => $data['odometer_at_intake'] ?? null,
                'notes' => $data['notes'] ?? '',
                'created_by' => $context->userId,
            ])->save();

            $this->lines->replace($order, $lines, $this->settings->forAccount($account, $branchId));
            $this->lines->replaceTasks($order, is_array($data['task_ids'] ?? null) ? array_values(array_filter($data['task_ids'], 'is_string')) : []);
            $this->journal->event($order, WorkOrderStatus::Draft, $now);
            $this->journal->audit($order, 'created', null);

            return $order;
        });
    }
}

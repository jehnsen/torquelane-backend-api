<?php

declare(strict_types=1);

namespace App\Actions\Parts;

use App\Actions\Fleet\FleetQueries;
use App\Domain\Parts\FleetPartFacts;
use App\Domain\Parts\PartDemandRow;
use App\Domain\Parts\PartsForecast;
use App\Domain\Parts\PartUsage;
use App\Domain\Parts\PurchaseCoverage;
use App\Domain\Parts\WorkCoverage;
use App\Domain\PurchaseOrders\PurchaseOrderStatus;
use App\Models\CustomerAccount;
use App\Models\FleetPart;
use App\Models\FleetPartUsage;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Models\WorkOrderTask;
use App\Tenancy\TenantManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The read side of a customer account's own spare parts and its demand
 * forecast. Portal sessions see their own account's parts; staff every
 * account's (a forecast is always for one account: stock is per account).
 */
final class PartsQueries
{
    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly FleetQueries $fleet,
    ) {}

    /**
     * @return Builder<FleetPart>
     */
    public function parts(): Builder
    {
        return FleetPart::query()->visibleTo($this->tenancy->require());
    }

    /**
     * @param  array{customer_account_id?: string, include_inactive?: bool}  $filters
     * @return LengthAwarePaginator<int, FleetPart>
     */
    public function page(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = $this->parts()->with('usages');
        if (isset($filters['customer_account_id'])) {
            $query->where('customer_account_id', $filters['customer_account_id']);
        }
        if (! ($filters['include_inactive'] ?? false)) {
            $query->where('is_active', true);
        }

        return $query->orderBy('customer_account_id')->orderBy('position')->orderBy('sku')->paginate($perPage);
    }

    /**
     * The account's forecast over `$horizonWeeks`: its vehicles' PMS due
     * items, less what its live work orders and open purchase orders already
     * cover, against its own stock.
     *
     * @return list<PartDemandRow>
     */
    public function forecast(CustomerAccount $account, int $horizonWeeks): array
    {
        /** @var list<Vehicle> $vehicles */
        $vehicles = array_values($this->fleet->vehicles()->where('customer_account_id', $account->id)->orderBy('id')->get()->all());
        $vehicleIds = array_map(fn (Vehicle $v): string => $v->id, $vehicles);
        $health = $this->fleet->pmsActive() ? $this->fleet->fleetHealth($vehicles) : [];

        return PartsForecast::demand(
            $health,
            $this->workCoverage($vehicleIds),
            $this->purchaseCoverage($account->id),
            $this->partFacts($account->id),
            $this->usages($account->id),
            $horizonWeeks,
            $this->fleet->today(),
        );
    }

    /**
     * @param  list<PartDemandRow>  $rows
     */
    public function summary(array $rows, int $horizonWeeks): string
    {
        return PartsForecast::summarise($rows, $horizonWeeks);
    }

    /**
     * The account's vehicles' plates, to label the forecast's due items.
     *
     * @return array<string, string> vehicle id → plate
     */
    public function plates(CustomerAccount $account): array
    {
        return Vehicle::query()->where('customer_account_id', $account->id)->pluck('plate_number', 'id')->map(fn (mixed $plate): string => is_string($plate) ? $plate : '')->all();
    }

    /**
     * @return list<FleetPartFacts>
     */
    public function partFacts(string $accountId): array
    {
        return array_values(FleetPart::query()->where('customer_account_id', $accountId)->where('is_active', true)->orderBy('position')->orderBy('id')->get()
            ->map(fn (FleetPart $part): FleetPartFacts => $part->facts())->all());
    }

    /**
     * @return list<PartUsage>  in position order (the forecast's tie order)
     */
    public function usages(string $accountId): array
    {
        return array_values(FleetPartUsage::query()
            ->whereIn('fleet_part_id', FleetPart::query()->select('id')->where('customer_account_id', $accountId))
            ->orderBy('position')->orderBy('id')->get()
            ->map(fn (FleetPartUsage $usage): PartUsage => $usage->usage())->all());
    }

    /**
     * Work orders on these vehicles, with the tasks each discharges.
     *
     * @param  list<string>  $vehicleIds
     * @return list<WorkCoverage>
     */
    private function workCoverage(array $vehicleIds): array
    {
        $orders = WorkOrder::query()->whereIn('vehicle_id', $vehicleIds)->orderBy('id')->get(['id', 'vehicle_id', 'status']);
        $tasks = [];
        foreach (WorkOrderTask::query()->whereIn('work_order_id', $orders->modelKeys())->get(['work_order_id', 'service_task_id']) as $task) {
            $tasks[$task->work_order_id][] = $task->service_task_id;
        }

        return array_values($orders->map(fn (WorkOrder $o): WorkCoverage => new WorkCoverage($o->status, $o->vehicle_id, $tasks[$o->id] ?? []))->all());
    }

    /**
     * The account's open purchase orders, with what each line covers.
     *
     * @return list<PurchaseCoverage>
     */
    private function purchaseCoverage(string $accountId): array
    {
        $orders = PurchaseOrder::query()
            ->where('customer_account_id', $accountId)
            ->whereIn('status', [PurchaseOrderStatus::Draft->value, PurchaseOrderStatus::Sent->value])
            ->with(['lines.serviceTasks:id', 'lines.vehicles:id'])
            ->orderBy('id')
            ->get();

        return array_values($orders->map(fn (PurchaseOrder $po): PurchaseCoverage => new PurchaseCoverage(
            $po->status,
            array_values($po->lines->map(fn (PurchaseOrderLine $line): array => [
                'taskIds' => array_values(array_map('strval', $line->serviceTasks->modelKeys())),
                'vehicleIds' => array_values(array_map('strval', $line->vehicles->modelKeys())),
            ])->all()),
        ))->all());
    }
}

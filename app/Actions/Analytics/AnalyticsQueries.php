<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Actions\Fleet\FleetQueries;
use App\Actions\Fleet\VehicleView;
use App\Actions\WorkOrders\ApprovalSettingsResolver;
use App\Actions\WorkOrders\WorkOrderQueries;
use App\Domain\Analytics\AnalyticsOrder;
use App\Domain\Analytics\AnalyticsVehicle;
use App\Domain\Approvals\ApprovalSettings;
use App\Domain\Approvals\RequestLine;
use App\Domain\Approvals\RequestOrder;
use App\Domain\Fleet\VehicleHealth;
use App\Domain\Shared\Num;
use App\Models\CustomerAccount;
use App\Models\ServiceTask;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Models\WorkOrderLine;
use App\Tenancy\TenantManager;

/**
 * Loads one scope's fleet for the analytics screens (dashboard, schedule,
 * reports, requests): the caller's vehicles (staff may narrow to one
 * account), their health, and the work orders raised for them, in creation
 * order so ranking ties keep ../web's array order. Everything is read once
 * per request; the series themselves are App\Domain\Analytics.
 */
final class AnalyticsQueries
{
    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly FleetQueries $fleet,
        private readonly WorkOrderQueries $orders,
        private readonly ApprovalSettingsResolver $settings,
    ) {}

    /** The account a staff caller narrowed to (404 outside their scope), or null. */
    public function account(?string $accountId): ?CustomerAccount
    {
        return $accountId === null ? null : $this->fleet->account($accountId);
    }

    /**
     * @return list<VehicleView> in creation order
     */
    public function vehicles(?CustomerAccount $account): array
    {
        $query = $this->fleet->vehicles();
        if ($account !== null) {
            $query->where('customer_account_id', $account->id);
        }
        /** @var list<Vehicle> $vehicles */
        $vehicles = array_values($query->orderBy('id')->get()->all());

        return $this->fleet->views($vehicles);
    }

    /**
     * @param  list<VehicleView>  $views
     * @return list<VehicleHealth>
     */
    public static function health(array $views): array
    {
        return array_values(array_filter(array_map(fn (VehicleView $v): ?VehicleHealth => $v->health, $views)));
    }

    /**
     * @param  list<VehicleView>  $views
     * @return list<AnalyticsVehicle>
     */
    public static function rankable(array $views): array
    {
        return array_map(fn (VehicleView $v): AnalyticsVehicle => new AnalyticsVehicle(
            $v->vehicle->id,
            $v->vehicle->plate_number,
            $v->vehicle->make ?? '',
            $v->vehicle->model ?? '',
            $v->facts->odometer,
            $v->facts->avgDailyKm,
        ), $views);
    }

    /**
     * The scope's work orders: an order belongs to the account stamped on it
     * at creation, not to its vehicle's current owner.
     *
     * @return list<WorkOrder> relations loaded, in creation order
     */
    public function workOrders(?CustomerAccount $account): array
    {
        $query = $this->orders->orders()->with(WorkOrderQueries::RELATIONS)->orderBy('id');
        if ($account !== null) {
            $query->where('customer_account_id', $account->id);
        }

        return array_values($query->get()->all());
    }

    /**
     * @param  list<WorkOrder>  $orders
     * @return list<AnalyticsOrder>
     */
    public function analyticsOrders(array $orders): array
    {
        return array_map(function (WorkOrder $order): AnalyticsOrder {
            $facts = $this->orders->fact($order);

            return new AnalyticsOrder(
                $order->vehicle_id,
                $order->status,
                $order->type,
                $facts->completedOn,
                $order->parts_cost_cents,
                $order->labor_cost_cents,
                $facts->costCents(),
                $facts->taskIds,
            );
        }, $orders);
    }

    /**
     * Each order with its own effective settings (organization → branch →
     * account), as the approvals queue judges it.
     *
     * @param  list<WorkOrder>  $orders
     * @return list<RequestOrder>
     */
    public function requestOrders(array $orders): array
    {
        $settings = [];

        return array_map(function (WorkOrder $order) use (&$settings): RequestOrder {
            $key = $order->customer_account_id.'|'.$order->branch_id;
            $settings[$key] ??= $this->settings->forOrder($order);

            return new RequestOrder(
                $order->id,
                $order->status,
                $order->pending_approval_entered_at,
                $order->approval_wait_hours === null ? null : Num::of($order->approval_wait_hours),
                array_values($order->lines->map(fn (WorkOrderLine $line): RequestLine => new RequestLine($line->id, $line->cost(), $line->approval_status, $line->approved_at))->all()),
                $settings[$key],
            );
        }, $orders);
    }

    /**
     * The settings the scope's own budget and SLA come from: a portal user's
     * (or the narrowed) account's, else the selected branch's.
     */
    public function scopeSettings(?CustomerAccount $account): ApprovalSettings
    {
        $accountId = $account->id ?? $this->tenancy->require()->customerAccountId();

        return $accountId !== null
            ? $this->settings->forAccount($account ?? CustomerAccount::query()->findOrFail($accountId), null)
            : $this->settings->forBranch($this->tenancy->require()->selectedBranchId);
    }

    /**
     * Catalogue estimated cost by task id (inactive tasks included: an
     * item's task may have been retired since).
     *
     * @return array<string, int>
     */
    public function taskCosts(): array
    {
        $costs = [];
        foreach (ServiceTask::query()->get(['id', 'estimated_cost_cents']) as $task) {
            $costs[$task->id] = $task->estimated_cost_cents->getMinorAmount()->toInt();
        }

        return $costs;
    }

    /** @return array<string, ServiceTask> */
    public function tasks(): array
    {
        return $this->fleet->tasksById();
    }

    public function fleet(): FleetQueries
    {
        return $this->fleet;
    }
}

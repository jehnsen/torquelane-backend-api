<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Analytics\AnalyticsQueries;
use App\Actions\Billing\CreditLimit;
use App\Actions\Fleet\FleetQueries;
use App\Actions\WorkOrders\AutoScheduleWorkOrders;
use App\Actions\WorkOrders\CompleteWorkOrder;
use App\Actions\WorkOrders\CreateWorkOrder;
use App\Actions\WorkOrders\DecideLines;
use App\Actions\WorkOrders\EditWorkOrder;
use App\Actions\WorkOrders\FinishWorkOrder;
use App\Actions\WorkOrders\ScheduleWorkOrder;
use App\Actions\WorkOrders\SendForApproval;
use App\Actions\WorkOrders\WorkOrderQueries;
use App\Actions\WorkOrders\WorkOrderView;
use App\Http\Requests\AutoScheduleRequest;
use App\Http\Requests\CollectWorkOrdersRequest;
use App\Http\Requests\DecideLinesRequest;
use App\Http\Requests\ListWorkOrdersRequest;
use App\Http\Requests\SaveWorkOrderRequest;
use App\Http\Requests\WorkOrderLinesRequest;
use App\Http\Requests\WorkOrderStepRequest;
use App\Http\Resources\WorkOrderCollection;
use App\Http\Resources\WorkOrderResource;
use App\Models\CustomerAccount;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Work orders: draft → pending approval → approved → in progress → ready
 * for billing → completed (projected over nine stored statuses). Every step
 * is one transaction on the locked order, writing its status event, approval
 * log entries and audit row; WorkOrderMachine decides what is legal.
 */
final class WorkOrderController
{
    /**
     * List work orders
     *
     * Portal users see their account's orders; staff see the organization's,
     * limited to their branches (a portal request not yet taken into a branch
     * is visible to all staff). Filter with `status[]`, `stage` (active,
     * completed, cancelled), `type`, `q` (reference, title, technician,
     * vendor, plate, customer), `customer_account_id`, `vehicle_id`, `branch_id`,
     * `technician_id`, `bay_id`, `scheduled_for`; order with `sort` (opened,
     * the default; scheduled; completed; queue).
     */
    public function index(ListWorkOrdersRequest $request, WorkOrderQueries $orders): WorkOrderCollection
    {
        Gate::authorize('viewAny', WorkOrder::class);

        return new WorkOrderCollection($orders->page($request->filters(), $request->perPage()));
    }

    /**
     * Work order totals
     *
     * What the list screens total, under the same filters as the list:
     * orders per bucket (active, completed, cancelled, all) within the scope
     * filters (`customer_account_id`, `vehicle_id`, `branch_id`), and the
     * count and value in centavos (labour plus resolved parts) of the fully
     * filtered set.
     */
    public function summary(ListWorkOrdersRequest $request, WorkOrderQueries $orders): JsonResponse
    {
        Gate::authorize('viewAny', WorkOrder::class);

        return new JsonResponse(['data' => $orders->summary($request->filters())]);
    }

    /**
     * Raise a work order (draft)
     *
     * `workorder:create`. Starts as an unnumbered draft — the number is issued
     * when it is sent for approval. Lines carry quantities and rates; the
     * server prices them (any cost or total sent is ignored). Staff raise it
     * in a branch (`branch_id`, else X-Branch-Id, else their only branch); a
     * suspended account takes no new work (403 account_suspended). An account
     * over its credit limit still gets the work: the response adds
     * `warnings: [{code: credit_limit_exceeded, …}]` and the override is logged.
     */
    public function store(SaveWorkOrderRequest $request, FleetQueries $fleet, WorkOrderQueries $orders, CreateWorkOrder $create): JsonResponse
    {
        $data = $request->workOrderData();
        $vehicle = $fleet->vehicle(is_string($data['vehicle_id'] ?? null) ? $data['vehicle_id'] : '');
        $account = $fleet->account($vehicle->customer_account_id);
        Gate::authorize('create', [WorkOrder::class, $account]);
        Gate::authorize('createWorkFor', $account);

        $order = $create->handle($vehicle, $data, $request->lines());
        $resource = new WorkOrderResource($orders->view($order));
        if ($create->lastCreditWarning !== null) {
            $resource->additional(['warnings' => [CreditLimit::warning($create->lastCreditWarning)]]);
        }

        return $resource->response()->setStatusCode(201);
    }

    /**
     * Show a work order
     */
    public function show(WorkOrder $workOrder, WorkOrderQueries $orders): WorkOrderResource
    {
        Gate::authorize('view', $workOrder);

        return new WorkOrderResource($orders->view($workOrder));
    }

    /**
     * Edit a draft (updateDraft)
     *
     * `workorder:update`. A declined quote reopens as a draft (it keeps its
     * number). Any other status is 409.
     */
    public function update(SaveWorkOrderRequest $request, WorkOrder $workOrder, WorkOrderQueries $orders, EditWorkOrder $edit): WorkOrderResource
    {
        Gate::authorize('update', $workOrder);

        return new WorkOrderResource($orders->view($edit->updateDraft($workOrder, $request->workOrderData())));
    }

    /**
     * Record a draft's lines (recordLines)
     *
     * `workorder:update`. The full list: lines with an `id` are updated, new
     * ones created, missing ones removed. Every cost is recomputed from
     * quantity × rate on the server.
     */
    public function lines(WorkOrderLinesRequest $request, WorkOrder $workOrder, WorkOrderQueries $orders, EditWorkOrder $edit): WorkOrderResource
    {
        Gate::authorize('update', $workOrder);

        return new WorkOrderResource($orders->view($edit->recordLines($workOrder, $request->lines())));
    }

    /**
     * Send the quotation for approval
     *
     * `workorder:update`. Issues the order number. Under the account's
     * auto-approve ceiling the system approves every line and the order opens
     * as approved.
     */
    public function send(WorkOrder $workOrder, WorkOrderQueries $orders, SendForApproval $send): WorkOrderResource
    {
        Gate::authorize('update', $workOrder);

        return new WorkOrderResource($orders->view($send->handle($workOrder)));
    }

    /**
     * Decide lines (approve, decline, defer)
     *
     * `workorder:approve`, within the role's approval band for the pending
     * amount. Declining a safety-critical line needs a `note`. The order's
     * status follows from the lines.
     */
    public function decide(DecideLinesRequest $request, WorkOrder $workOrder, WorkOrderQueries $orders, DecideLines $decide): WorkOrderResource
    {
        Gate::authorize('decide', $workOrder);

        return new WorkOrderResource($orders->view($decide->handle($workOrder, $request->decisions())));
    }

    /**
     * Schedule onto a bay
     *
     * Staff, `workorder:update`. Approved work gets a date, a time and a bay
     * (and optionally a technician); a scheduled order may be re-booked.
     */
    public function schedule(WorkOrderStepRequest $request, WorkOrder $workOrder, WorkOrderQueries $orders, ScheduleWorkOrder $schedule): WorkOrderResource
    {
        Gate::authorize('schedule', $workOrder);

        return new WorkOrderResource($orders->view($schedule->schedule(
            $workOrder,
            $request->string('scheduled_for')->toString(),
            $request->string('scheduled_time')->toString(),
            $request->string('bay_id')->lower()->toString(),
            $request->technicianId(),
        )));
    }

    /**
     * Start work
     */
    public function start(WorkOrderStepRequest $request, WorkOrder $workOrder, WorkOrderQueries $orders, ScheduleWorkOrder $schedule): WorkOrderResource
    {
        Gate::authorize('update', $workOrder);

        return new WorkOrderResource($orders->view($schedule->start($workOrder, $request->technicianId())));
    }

    /**
     * Record the work done (complete)
     *
     * `workorder:complete`, while in progress: findings, odometer at service,
     * parts fitted, tasks discharged.
     */
    public function complete(WorkOrderStepRequest $request, WorkOrder $workOrder, WorkOrderQueries $orders, CompleteWorkOrder $complete): WorkOrderResource
    {
        Gate::authorize('complete', $workOrder);

        return new WorkOrderResource($orders->view($complete->complete($workOrder, $request->completion())));
    }

    /**
     * Close out
     *
     * `workorder:complete`. Refused (409, `details.reason` variance_exceeded)
     * when actual cost passes the approved amount by more than the variance
     * threshold, unless `variance_approved` is sent by someone who can
     * approve the actual amount. Closing resets the vehicle's PMS intervals.
     */
    public function close(WorkOrderStepRequest $request, WorkOrder $workOrder, WorkOrderQueries $orders, CompleteWorkOrder $complete): WorkOrderResource
    {
        Gate::authorize('complete', $workOrder);

        return new WorkOrderResource($orders->view($complete->close($workOrder, $request->boolean('variance_approved'))));
    }

    /**
     * Mark collected
     *
     * Staff. Revenue is recognised here, not at close.
     */
    /**
     * Auto-schedule the overdue queue
     *
     * `workorder:create`. Raises one draft per proposal in
     * `GET /analytics/auto-schedule` (recomputed here, so an item already
     * covered is never booked twice): the catalogue task as its one line,
     * scheduled from tomorrow at three a day. Staff narrow with
     * `customer_account_id` and name the `branch_id` taking the work in (else
     * X-Branch-Id, else their only repair branch).
     */
    public function autoSchedule(AutoScheduleRequest $request, AnalyticsQueries $analytics, AutoScheduleWorkOrders $schedule, WorkOrderQueries $orders): JsonResponse
    {
        Gate::authorize('autoSchedule', WorkOrder::class);
        $account = $analytics->account($request->accountId());
        $preview = $schedule->preview($account);

        $accountIds = array_values(array_unique(array_map(fn (Vehicle $v): string => $v->customer_account_id, array_intersect_key($preview['vehicles'], array_flip(array_map(fn ($p): string => $p->vehicleId, $preview['proposals']))))));
        foreach (CustomerAccount::query()->whereIn('id', $accountIds)->get() as $owner) {
            Gate::authorize('create', [WorkOrder::class, $owner]);
            Gate::authorize('createWorkFor', $owner);
        }

        $created = $schedule->handle($account, $accountIds, $request->branchId());

        return new JsonResponse(['data' => [
            'created' => count($created),
            'work_orders' => array_map(fn (WorkOrderView $view): array => (new WorkOrderResource($view))->resolve($request), $orders->views($created)),
        ]], $created === [] ? 200 : 201);
    }

    /**
     * Release several jobs
     *
     * The check-out panel's "Mark collected": every order in `work_order_ids`
     * must be closed and not yet collected (409 invalid_transition
     * otherwise, and none is collected). Staff with `workorder:complete`.
     */
    public function collectMany(CollectWorkOrdersRequest $request, WorkOrderQueries $orders, FinishWorkOrder $finish): JsonResponse
    {
        $models = $orders->orders()->whereIn('id', $request->ids())->get()->keyBy('id');
        $selected = [];
        foreach ($request->ids() as $id) {
            $order = $models->get($id) ?? throw (new ModelNotFoundException)->setModel(WorkOrder::class, [$id]);
            Gate::authorize('collect', $order);
            $selected[] = $order;
        }

        $collected = $finish->markAllCollected($selected);

        return new JsonResponse(['data' => [
            'collected' => count($collected),
            'work_orders' => array_map(fn (WorkOrderView $view): array => (new WorkOrderResource($view))->resolve($request), $orders->views($collected)),
        ]]);
    }

    public function collect(WorkOrder $workOrder, WorkOrderQueries $orders, FinishWorkOrder $finish): WorkOrderResource
    {
        Gate::authorize('collect', $workOrder);

        return new WorkOrderResource($orders->view($finish->markCollected($workOrder)));
    }

    /**
     * Cancel
     */
    public function cancel(WorkOrderStepRequest $request, WorkOrder $workOrder, WorkOrderQueries $orders, FinishWorkOrder $finish): WorkOrderResource
    {
        Gate::authorize('update', $workOrder);

        return new WorkOrderResource($orders->view($finish->cancel($workOrder, $request->string('reason')->toString())));
    }
}

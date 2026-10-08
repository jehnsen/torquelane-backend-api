<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Actions\WorkOrders\ApprovalSettingsResolver;
use App\Domain\Alerts\Alerts;
use App\Domain\Alerts\AlertView;
use App\Domain\Alerts\WorkOrderAlertFacts;
use App\Domain\Documents\DocumentFacts;
use App\Domain\Fleet\Compliance;
use App\Domain\Fleet\FleetSummary;
use App\Domain\Fleet\FleetThresholds;
use App\Domain\Fleet\Pms;
use App\Domain\Fleet\ServiceTaskFacts;
use App\Domain\Fleet\TaskState;
use App\Domain\Fleet\VehicleFacts;
use App\Domain\Fleet\VehicleHealth;
use App\Domain\Maintenance\MeterKind;
use App\Domain\Maintenance\MeterRate;
use App\Domain\Maintenance\MeterReading as Reading;
use App\Domain\Modules\Module;
use App\Domain\Shared\Calendar;
use App\Domain\Shared\Num;
use App\Domain\WorkOrders\WorkOrderStatus;
use App\Models\AlertInteraction;
use App\Models\CustomerAccount;
use App\Models\Document;
use App\Models\MaintenanceState;
use App\Models\MeterReading;
use App\Models\ServiceTask;
use App\Models\Vehicle;
use App\Models\VehicleOwnership;
use App\Models\WorkOrder;
use App\Tenancy\ModuleGate;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;

/**
 * The read side of the fleet: loads what the engine needs from the database
 * (effective readings, maintenance state, the catalogue) and runs the ported
 * rules. Every query starts from the tenant scope; portal sessions see only
 * vehicles their account owns now, and documents filed under their account.
 *
 * "Today" is the Manila business clock (R9); tests freeze it.
 */
final class FleetQueries
{
    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly ModuleGate $modules,
        private readonly ApprovalSettingsResolver $settings,
    ) {}

    /**
     * What the work-order alert rules read: booked or running work (a slot
     * that can slip) and quotations waiting on approval, in creation order.
     *
     * @return list<WorkOrderAlertFacts>
     */
    private function workOrderAlertFacts(): array
    {
        $orders = WorkOrder::query()->visibleTo($this->tenancy->require())
            ->whereIn('status', ['scheduled', 'in_progress', 'pending_approval'])
            ->withCount(['lines as pending_line_count' => fn (Builder $q) => $q->where('approval_status', 'pending')])
            ->orderBy('id')
            ->get();

        $facts = [];
        foreach ($orders as $order) {
            $pending = $order->status === WorkOrderStatus::PendingApproval;
            if (! $pending && $order->scheduled_for === null) {
                continue;
            }
            $facts[] = new WorkOrderAlertFacts(
                $order->id,
                $order->reference,
                $order->title,
                $order->status->value,
                $order->scheduled_for?->toDateString() ?? '',
                $order->vehicle_id,
                $order->pending_approval_entered_at === null ? null : Calendar::local($order->pending_approval_entered_at)->format('Y-m-d\TH:i:s.vP'),
                is_int($count = $order->getAttribute('pending_line_count')) ? $count : 0,
            );
        }

        return $facts;
    }

    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now('Asia/Manila');
    }

    /**
     * @return Builder<Vehicle>
     */
    public function vehicles(bool $includeArchived = false): Builder
    {
        $query = Vehicle::query()->visibleTo($this->tenancy->require());

        return $includeArchived ? $query : $query->whereNull('archived_at');
    }

    /**
     * The active catalogue, in catalogue order (ties in urgency keep it).
     *
     * @return list<ServiceTask>
     */
    public function tasks(): array
    {
        return array_values(ServiceTask::query()->where('is_active', true)->orderBy('position')->orderBy('code')->get()->all());
    }

    /**
     * @return list<ServiceTaskFacts>
     */
    public function taskFacts(): array
    {
        return array_map(fn (ServiceTask $task): ServiceTaskFacts => $task->facts(), $this->tasks());
    }

    /**
     * Engine facts for each vehicle, in the given order. Readings and states
     * are loaded in two queries for the whole set.
     *
     * @param  list<Vehicle>  $vehicles
     * @return array<string, VehicleFacts> by vehicle id
     */
    public function facts(array $vehicles): array
    {
        $ids = array_map(fn (Vehicle $vehicle): string => $vehicle->id, $vehicles);
        $readings = $this->effectiveReadings($ids);

        $states = [];
        foreach (MaintenanceState::query()->whereIn('vehicle_id', $ids)->get() as $state) {
            $states[(string) $state->vehicle_id][$state->service_task_id] = new TaskState(Num::of($state->last_done_value), $state->last_done_on->toDateString());
        }

        $facts = [];
        foreach ($vehicles as $vehicle) {
            $own = $readings[$vehicle->id] ?? [];
            $current = MeterRate::current($own);

            $facts[$vehicle->id] = new VehicleFacts(
                $vehicle->id,
                $vehicle->plate_number,
                $current === null ? 0 : $current->value,
                $current === null ? ($vehicle->acquired_on ?? $vehicle->created_at)->setTimezone('Asia/Manila')->toDateString() : $current->readOn,
                MeterRate::dailyRate($own),
                $states[$vehicle->id] ?? [],
                $vehicle->status,
                $vehicle->driver_licence_expiry?->toDateString(),
                (string) $vehicle->assigned_to,
            );
        }

        return $facts;
    }

    /**
     * Vehicles with their computed state: odometer, PMS health (where
     * repair_pms is active), compliance from the documents the caller can see.
     *
     * @param  list<Vehicle>  $vehicles
     * @return list<VehicleView>
     */
    public function views(array $vehicles): array
    {
        if ($vehicles === []) {
            return [];
        }

        $facts = $this->facts($vehicles);
        $today = $this->today();
        $tasks = $this->pmsActive() ? $this->taskFacts() : null;
        $documents = $this->documentFacts(array_keys($facts));

        return array_map(function (Vehicle $vehicle) use ($facts, $today, $tasks, $documents): VehicleView {
            $vehicleFacts = $facts[$vehicle->id];

            return new VehicleView(
                $vehicle,
                $vehicleFacts,
                $tasks === null ? null : Pms::evaluateVehicle($vehicleFacts, $tasks, $today),
                Compliance::vehicleStatus($vehicle->id, $vehicleFacts->driverLicenceExpiry, $documents, $today),
                Pms::odometerAgeDays($vehicleFacts, $today),
                Pms::isOdometerStale($vehicleFacts, $today),
            );
        }, $vehicles);
    }

    public function view(Vehicle $vehicle): VehicleView
    {
        return $this->views([$vehicle])[0];
    }

    public function vehicleFacts(Vehicle $vehicle): VehicleFacts
    {
        return $this->facts([$vehicle])[$vehicle->id];
    }

    public function health(Vehicle $vehicle): VehicleHealth
    {
        return Pms::evaluateVehicle($this->vehicleFacts($vehicle), $this->taskFacts(), $this->today());
    }

    /**
     * @param  list<Vehicle>  $vehicles
     * @return list<VehicleHealth>
     */
    public function fleetHealth(array $vehicles): array
    {
        return Pms::evaluateFleet(array_values($this->facts($vehicles)), $this->taskFacts(), $this->today());
    }

    /**
     * @return Builder<Document>
     */
    public function documents(): Builder
    {
        return Document::query()->visibleTo($this->tenancy->require());
    }

    /**
     * @param  list<string>|null  $vehicleIds  null: every visible document
     * @return list<DocumentFacts>
     */
    public function documentFacts(?array $vehicleIds = null): array
    {
        $query = $this->documents();
        if ($vehicleIds !== null) {
            $query->whereIn('vehicle_id', $vehicleIds);
        }

        return array_map(fn (Document $document): DocumentFacts => $document->facts(), array_values($query->orderBy('id')->get()->all()));
    }

    /** Whether PMS rules apply to this session (repair_pms active where it works). */
    public function pmsActive(): bool
    {
        return in_array(Module::RepairPms, $this->modules->active(), true);
    }

    /**
     * Alerts derived for the caller's scope, with their own read/dismiss state.
     * PMS and work-order alerts only where repair_pms is active; document and
     * licence alerts always. The approval SLA is the scope's own: a portal
     * user's account settings, staff the selected branch's.
     */
    public function alerts(): AlertView
    {
        $context = $this->tenancy->require();
        $vehicles = array_values($this->vehicles()->orderBy('id')->get()->all());
        $repair = $this->pmsActive();

        $health = $this->fleetHealth($vehicles);
        if (! $repair) {
            $health = array_map(fn (VehicleHealth $h): VehicleHealth => new VehicleHealth($h->vehicle, [], 'ok', 0, 0, null, 100), $health);
        }

        $accountId = $context->customerAccountId();
        $settings = $accountId !== null
            ? $this->settings->forAccount(CustomerAccount::query()->findOrFail($accountId), null)
            : $this->settings->forBranch($context->selectedBranchId);

        $alerts = Alerts::build($health, $repair ? $this->workOrderAlertFacts() : [], $this->documentFacts(), $settings->slaHours, $this->today());

        $read = [];
        $dismissed = [];
        $rows = AlertInteraction::query()->where('user_id', $context->userId)->where('scope_key', $context->scope->key())->get();
        foreach ($rows as $row) {
            if ($row->read_at !== null) {
                $read[] = $row->alert_id;
            }
            if ($row->dismissed_at !== null) {
                $dismissed[] = $row->alert_id;
            }
        }

        return Alerts::view($alerts, $read, $dismissed);
    }

    /**
     * Effective km readings (not voided, not void rows), per vehicle.
     *
     * @param  list<string>  $vehicleIds
     * @return array<string, list<Reading>>
     */
    public function effectiveReadings(array $vehicleIds): array
    {
        $rows = MeterReading::query()
            ->whereIn('vehicle_id', $vehicleIds)
            ->where('meter_kind', MeterKind::Km->value)
            ->get();

        $voided = [];
        foreach ($rows as $row) {
            if ($row->voids_reading_id !== null) {
                $voided[$row->voids_reading_id] = true;
            }
        }

        $readings = [];
        foreach ($rows as $row) {
            if ($row->value === null || isset($voided[$row->id])) {
                continue;
            }
            $readings[(string) $row->vehicle_id][] = new Reading($row->id, Num::of($row->value), $row->read_on->toDateString());
        }

        return $readings;
    }

    /**
     * @param  array{status?: string, customer_account_id?: string, q?: string}  $filters
     * @return LengthAwarePaginator<int, VehicleView>
     */
    public function vehiclePage(array $filters, bool $includeArchived, int $perPage): LengthAwarePaginator
    {
        $query = $this->vehicles($includeArchived);
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['customer_account_id'])) {
            $query->where('customer_account_id', $filters['customer_account_id']);
        }
        if (isset($filters['q']) && $filters['q'] !== '') {
            $q = $filters['q'];
            $query->where(fn (Builder $w) => $w->where('plate_normalized', $q)->orWhere('vin_normalized', $q)->orWhere('plate_normalized', 'like', addcslashes($q, '%_\\').'%'));
        }

        $page = $query->orderBy('plate_normalized')->orderBy('id')->paginate($perPage);
        /** @var list<Vehicle> $vehicles */
        $vehicles = array_values($page->items());

        return new Paginator($this->views($vehicles), $page->total(), $page->perPage(), $page->currentPage());
    }

    /** A visible, unarchived vehicle (404 otherwise). */
    public function vehicle(string $id): Vehicle
    {
        return $this->vehicles()->findOrFail($id);
    }

    /**
     * The account a write names, within the caller's reach (404 otherwise);
     * a portal caller's own account when none is named.
     */
    public function account(?string $accountId): CustomerAccount
    {
        $context = $this->tenancy->require();

        return CustomerAccount::query()->visibleTo($context)->findOrFail($accountId ?? $context->customerAccountId());
    }

    /**
     * @return LengthAwarePaginator<int, VehicleOwnership>
     */
    public function ownerships(Vehicle $vehicle, int $perPage): LengthAwarePaginator
    {
        return VehicleOwnership::query()->where('vehicle_id', $vehicle->id)->orderByDesc('from_date')->orderByDesc('id')->paginate($perPage);
    }

    /**
     * Every reading row, void rows included, newest first.
     *
     * @return LengthAwarePaginator<int, MeterReading>
     */
    public function readings(Vehicle $vehicle, int $perPage): LengthAwarePaginator
    {
        return MeterReading::query()->where('vehicle_id', $vehicle->id)->orderByDesc('read_on')->orderByDesc('id')->paginate($perPage);
    }

    /**
     * @return LengthAwarePaginator<int, ServiceTask>
     */
    public function taskPage(int $perPage): LengthAwarePaginator
    {
        return ServiceTask::query()->orderBy('position')->orderBy('code')->paginate($perPage);
    }

    /**
     * @return array<string, ServiceTask>
     */
    public function tasksById(): array
    {
        $tasks = [];
        foreach ($this->tasks() as $task) {
            $tasks[$task->id] = $task;
        }

        return $tasks;
    }

    /**
     * @param  array{vehicle_id?: string, customer_account_id?: string, kind?: string, expiring_within?: int}  $filters
     * @return LengthAwarePaginator<int, Document>
     */
    public function documentPage(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = $this->documents();
        foreach (['vehicle_id', 'customer_account_id', 'kind'] as $column) {
            if (isset($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }
        if (isset($filters['expiring_within'])) {
            $query->whereNotNull('expires_on')->where('expires_on', '<=', Calendar::toDate(Calendar::addDays($this->today(), $filters['expiring_within'])));
        }

        return $query->orderByDesc('uploaded_on')->orderByDesc('id')->paginate($perPage);
    }

    /**
     * Dashboard KPIs over the caller's (optionally one account's) vehicles.
     *
     * @return array{0: FleetSummary, 1: array{total: int, byKind: list<array{label: string, count: int}>}, 2: array{expired: int, expiring: int, ok: int}}
     */
    public function summary(?string $accountId): array
    {
        $query = $this->vehicles();
        if ($accountId !== null) {
            $query->where('customer_account_id', $accountId);
        }
        /** @var list<Vehicle> $vehicles */
        $vehicles = array_values($query->orderBy('plate_normalized')->get()->all());

        $views = $this->views($vehicles);
        $health = array_values(array_filter(array_map(fn (VehicleView $v): ?VehicleHealth => $v->health, $views)));
        $documents = $this->documentFacts(array_map(fn (Vehicle $v): string => $v->id, $vehicles));

        $compliance = ['expired' => 0, 'expiring' => 0, 'ok' => 0];
        foreach ($views as $view) {
            match ($view->complianceStatus) {
                'expired' => $compliance['expired']++,
                'expiring' => $compliance['expiring']++,
                default => $compliance['ok']++,
            };
        }

        return [
            FleetSummary::of($health),
            Compliance::expiringSummary(
                array_map(fn (VehicleView $v): ?string => $v->facts->driverLicenceExpiry, $views),
                $documents,
                FleetThresholds::DASHBOARD_EXPIRY_WINDOW_DAYS,
                $this->today(),
            ),
            $compliance,
        ];
    }

    /** "Today" as a Manila business date. */
    public function todayDate(): string
    {
        return Calendar::toDate($this->today());
    }
}

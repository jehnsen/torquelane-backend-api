<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Billing\ManageInvoices;
use App\Actions\Billing\RecordPayment;
use App\Actions\Inventory\ManageStockCounts;
use App\Actions\Inventory\ProgressShopPurchaseOrder;
use App\Actions\Inventory\ReceiveGoods;
use App\Actions\Inventory\RecordOpeningStock;
use App\Actions\Inventory\SaveItem;
use App\Actions\Inventory\SaveShopPurchaseOrder;
use App\Actions\Inventory\StockLocations;
use App\Actions\Inventory\TransferStock;
use App\Domain\Access\Role;
use App\Domain\Approvals\ApprovalSettings;
use App\Domain\Crm\ConsentChannel;
use App\Domain\Crm\ConsentPurpose;
use App\Domain\Documents\DocumentKind;
use App\Domain\Maintenance\MeterKind;
use App\Domain\Modules\Module;
use App\Models\ApprovalLogEntry;
use App\Models\ApprovalSetting;
use App\Models\Bay;
use App\Models\Branch;
use App\Models\BranchModule;
use App\Models\Consent;
use App\Models\Contact;
use App\Models\CustomerAccount;
use App\Models\Document;
use App\Models\FleetPart;
use App\Models\FleetPartUsage;
use App\Models\Invitation;
use App\Models\MaintenanceState;
use App\Models\MeterReading;
use App\Models\Organization;
use App\Models\OrganizationModule;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderEvent;
use App\Models\PurchaseOrderLine;
use App\Models\ServiceTask;
use App\Models\Technician;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleOwnership;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Models\WorkOrderEvent;
use App\Models\WorkOrderLine;
use App\Models\WorkOrderPart;
use App\Models\WorkOrderTask;
use App\Tenancy\TenantContextResolver;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;

/**
 * The demo tenant exactly as DemoSeeder builds it, plus what the isolation
 * suite needs around it: a second organization ("Other Motorworks", the
 * golden fixtures' second provider) with every kind of record, a walk-in
 * individual account and pending invitations in the demo organization.
 *
 * `$ids` maps the demo seed's source ids (prov-mekanikomore, fc-actimed, …,
 * branch slugs, user emails, veh-001, wo-0208, …) to the ULIDs created;
 * extras are keyed `walk-in`, `invite:*` and `rival*` (the rival has a work
 * order with a line, task, part, event and approval-log entry).
 */
final class World
{
    /** @var array<string, string> */
    public array $ids = [];

    public static function build(): self
    {
        $world = new self;
        $seeder = new DemoSeeder;
        $seeder->run();
        $world->ids = $seeder->ids;

        app(TenantManager::class)->system('test world', function () use ($world): void {
            $world->demoExtras();
            $world->rival();
        });

        return $world;
    }

    public function id(string $key): string
    {
        return $this->ids[$key] ?? throw new LogicException("No world id for [{$key}].");
    }

    public function user(string $emailOrKey): User
    {
        return app(TenantManager::class)->system('test lookup', fn (): User => User::query()->findOrFail($this->id($emailOrKey)));
    }

    /**
     * Every demo account's email (the seeder's list).
     *
     * @return list<string>
     */
    public static function demoEmails(): array
    {
        return array_map(fn (array $user): string => $user[0], DemoSeeder::DEMO_USERS);
    }

    private function demoExtras(): void
    {
        $organizationId = $this->id('prov-mekanikomore');

        $walkIn = $this->account($organizationId, 'Jun Dela Cruz', CustomerAccount::INDIVIDUAL);
        $this->ids['walk-in'] = $walkIn->id;
        $this->ids['walk-in:contact'] = $this->contact($walkIn)->id;

        $owner = $this->id('owner@mekanikomore.ph');
        $this->ids['invite:portal'] = $this->invitation($organizationId, 'new.dispatcher@northwind.ph', Role::Operations, $this->id('fc-northwind'), $owner)->id;
        $this->ids['invite:staff'] = $this->invitation($organizationId, 'new.advisor@mekanikomore.ph', Role::ServiceAdvisor, null, $owner)->id;

        // A sibling account's own spare part and purchase order (the seed's are all Actimed's).
        $this->purchasing($organizationId, $this->id('fc-northwind'), 'northwind', $this->id('task:oil-filter'), $owner, 'PO-2026-0901');
    }

    /** A spare part with a usage, and a purchase order for it with a line and its first event. */
    private function purchasing(string $organizationId, string $accountId, string $key, string $taskId, string $actorId, string $reference): void
    {
        $part = new FleetPart;
        $part->forceFill([
            'organization_id' => $organizationId,
            'customer_account_id' => $accountId,
            'sku' => strtoupper($key).'-OIL',
            'name' => 'Oil filter ('.$key.')',
            'category' => 'engine',
            'unit_cost_cents' => 40000,
            'current_stock' => 1,
            'reorder_point' => 2,
            'preferred_vendor' => 'Parts Co',
        ])->save();
        $this->ids["{$key}:part"] = $part->id;

        $usage = new FleetPartUsage;
        $usage->forceFill(['organization_id' => $organizationId, 'fleet_part_id' => $part->id, 'service_task_id' => $taskId, 'quantity_per_service' => 1])->save();
        $this->ids["{$key}:part-usage"] = $usage->id;

        $order = new PurchaseOrder;
        $order->forceFill([
            'organization_id' => $organizationId,
            'customer_account_id' => $accountId,
            'reference' => $reference,
            'vendor' => 'Parts Co',
            'status' => 'draft',
            'created_on' => '2026-10-01',
            'created_by' => $actorId,
            'created_by_name' => 'Someone',
            'total_cents' => 80000,
        ])->save();
        $this->ids["{$key}:po"] = $order->id;

        $line = new PurchaseOrderLine;
        $line->forceFill([
            'organization_id' => $organizationId,
            'purchase_order_id' => $order->id,
            'customer_account_id' => $accountId,
            'fleet_part_id' => $part->id,
            'description' => $part->name,
            'quantity' => 2,
            'unit_cost_cents' => 40000,
            'line_total_cents' => 80000,
        ])->save();
        $this->ids["{$key}:po-line"] = $line->id;

        $event = new PurchaseOrderEvent;
        $event->forceFill(['organization_id' => $organizationId, 'purchase_order_id' => $order->id, 'status' => 'draft', 'at' => '2026-10-01T02:00:00Z', 'actor_name' => 'Someone'])->save();
        $this->ids["{$key}:po-event"] = $event->id;
    }

    private function rival(): void
    {
        $organization = new Organization;
        $organization->forceFill([
            'name' => 'Other Motorworks',
            'slug' => 'other',
            'support_email' => 'help@other.example',
            'brand_color' => '#aa3300',
            'status' => 'active',
        ])->save();
        $this->ids['rival'] = $organization->id;

        $branch = new Branch;
        $branch->forceFill(['organization_id' => $organization->id, 'name' => 'Other Motorworks Main', 'slug' => 'main'])->save();
        $this->ids['rival:branch'] = $branch->id;

        (new OrganizationModule)->forceFill(['organization_id' => $organization->id, 'module' => Module::RepairPms, 'enabled' => true])->save();
        (new BranchModule)->forceFill(['organization_id' => $organization->id, 'branch_id' => $branch->id, 'module' => Module::RepairPms, 'enabled' => true])->save();

        $bay = new Bay;
        $bay->forceFill(['organization_id' => $organization->id, 'branch_id' => $branch->id, 'name' => 'Rival Bay', 'capacity_hours_per_day' => '8'])->save();
        $this->ids['rival:bay'] = $bay->id;

        $technician = new Technician;
        $technician->forceFill([
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
            'name' => 'Rival Mechanic',
            'skill_tags' => ['mechanic'],
            'home_bay_id' => $bay->id,
        ])->save();
        $this->ids['rival:technician'] = $technician->id;

        $fleet = $this->account($organization->id, 'Rival Fleet Co', CustomerAccount::COMPANY);
        $this->ids['rival:account'] = $fleet->id;
        $this->ids['rival:contact'] = $this->contact($fleet)->id;
        $this->ids['rival:walk-in'] = $this->account($organization->id, 'Rival Walk-in', CustomerAccount::INDIVIDUAL)->id;

        $admin = $this->makeUser($organization->id, 'admin@other.example', Role::ProviderAdmin, null);
        $this->ids['rival:admin'] = $admin->id;
        $this->ids['rival:portal'] = $this->makeUser($organization->id, 'fleet@rivalfleet.example', Role::FleetManager, $fleet->id)->id;

        $this->ids['rival:invite'] = $this->invitation($organization->id, 'someone@rivalfleet.example', Role::Viewer, $fleet->id, $admin->id)->id;

        $this->rivalFleet($organization->id, $fleet->id);
    }

    private function rivalFleet(string $organizationId, string $accountId): void
    {
        $task = new ServiceTask;
        $task->forceFill([
            'organization_id' => $organizationId,
            'code' => 'oil-filter',
            'name' => 'Rival oil change',
            'category' => 'engine',
            'interval_km' => 5000,
            'interval_months' => 6,
            'critical' => true,
        ])->save();
        $this->ids['rival:task'] = $task->id;

        $vehicle = new Vehicle;
        $vehicle->forceFill([
            'organization_id' => $organizationId,
            'customer_account_id' => $accountId,
            'plate_number' => 'NBA 4821',
            'plate_normalized' => 'NBA4821',
            'status' => 'active',
            'driver_licence_expiry' => '2026-10-20',
        ])->save();
        $this->ids['rival:vehicle'] = $vehicle->id;

        (new VehicleOwnership)->forceFill(['organization_id' => $organizationId, 'vehicle_id' => $vehicle->id, 'customer_account_id' => $accountId, 'from_date' => '2025-01-01'])->save();

        foreach ([['2026-09-08', '10000'], ['2026-10-08', '11500']] as [$on, $value]) {
            (new MeterReading)->forceFill([
                'organization_id' => $organizationId,
                'asset_type' => 'vehicle',
                'asset_id' => $vehicle->id,
                'vehicle_id' => $vehicle->id,
                'meter_kind' => MeterKind::Km,
                'value' => $value,
                'read_on' => $on,
                'source' => 'import',
            ])->save();
        }

        (new MaintenanceState)->forceFill([
            'organization_id' => $organizationId,
            'asset_type' => 'vehicle',
            'asset_id' => $vehicle->id,
            'vehicle_id' => $vehicle->id,
            'service_task_id' => $task->id,
            'meter_kind' => MeterKind::Km,
            'last_done_value' => '5000',
            'last_done_on' => '2026-01-01',
        ])->save();

        $document = new Document;
        $document->forceFill([
            'organization_id' => $organizationId,
            'customer_account_id' => $accountId,
            'vehicle_id' => $vehicle->id,
            'kind' => DocumentKind::Ctpl,
            'name' => 'Rival CTPL',
            'expires_on' => '2026-10-15',
            'uploaded_by_name' => 'Rival Admin',
            'uploaded_on' => '2026-01-01',
        ])->save();
        $this->ids['rival:document'] = $document->id;

        $this->rivalWorkOrder($organizationId, $accountId, $vehicle->id, $task->id);

        $vendor = new Vendor;
        $vendor->forceFill(['organization_id' => $organizationId, 'name' => 'Rival Parts Co'])->save();
        $this->ids['rival:vendor'] = $vendor->id;
        $this->purchasing($organizationId, $accountId, 'rival', $task->id, $this->id('rival:admin'), 'PO-2026-0001');
        $this->rivalInventory($organizationId);
        $this->rivalBilling($accountId);
    }

    /** The rival's receivables, through the real Actions as its admin: an issued invoice, part-paid. */
    private function rivalBilling(string $accountId): void
    {
        $context = app(TenantContextResolver::class)->resolve($this->user('rival:admin'), null)->context;
        app(TenantManager::class)->actingAs($context, function () use ($accountId): void {
            $account = CustomerAccount::query()->findOrFail($accountId);
            $invoices = app(ManageInvoices::class);
            $invoice = $invoices->manual($account, $this->id('rival:branch'), [['description' => 'Rival diagnostic', 'quantity' => '1', 'unit_price_cents' => 150000]]);
            $invoices->issue($invoice);
            $this->ids['rival:invoice'] = $invoice->id;
            $this->ids['rival:invoice-line'] = (string) $invoice->lines()->value('id');
            $payment = app(RecordPayment::class)->record($account, ['branch_id' => $this->id('rival:branch'), 'method' => 'cash', 'amount_cents' => 50000]);
            $this->ids['rival:payment'] = $payment->id;
            $this->ids['rival:payment-allocation'] = (string) $payment->allocations()->value('id');
        });
    }

    /**
     * The rival's stock room, built through the real Actions as its admin:
     * two branches with stores, an item with an opening balance, a purchase
     * order issued and received, a count and a transfer between the stores.
     */
    private function rivalInventory(string $organizationId): void
    {
        $annex = new Branch;
        $annex->forceFill(['organization_id' => $organizationId, 'name' => 'Other Motorworks Annex', 'slug' => 'annex'])->save();
        $this->ids['rival:annex'] = $annex->id;

        $context = app(TenantContextResolver::class)->resolve($this->user('rival:admin'), null)->context;
        app(TenantManager::class)->actingAs($context, function () use ($annex): void {
            $locations = app(StockLocations::class);
            $main = $locations->storeOf($this->id('rival:branch'));
            $second = $locations->storeOf($annex->id);
            $this->ids['rival:location'] = $main->id;
            $this->ids['rival:location-annex'] = $second->id;

            $item = app(SaveItem::class)->create(['sku' => 'RIVAL-OIL', 'name' => 'Rival oil filter', 'item_type' => 'part', 'uom' => 'pc', 'default_price_cents' => 30000, 'preferred_vendor_id' => $this->id('rival:vendor')]);
            $this->ids['rival:item'] = $item->id;
            $this->ids['rival:item-setting'] = app(SaveItem::class)->setBranchSettings($item, $this->id('rival:branch'), ['reorder_point' => '5', 'reorder_qty' => '10', 'bin' => 'R1'])->id;

            app(RecordOpeningStock::class)->handle($main, [['item_id' => $item->id, 'quantity' => '10', 'unit_cost_cents' => 20000]], 'Opening balance');

            $order = app(SaveShopPurchaseOrder::class)->create(['branch_id' => $this->id('rival:branch'), 'vendor_id' => $this->id('rival:vendor'), 'lines' => [['item_id' => $item->id, 'quantity' => '4', 'unit_cost_cents' => 21000]]]);
            $this->ids['rival:shop-po'] = $order->id;
            app(ProgressShopPurchaseOrder::class)->issue($order);
            $order->load('lines');
            $this->ids['rival:shop-po-line'] = $order->lines[0]->id;
            $receipt = app(ReceiveGoods::class)->receive($order, ['lines' => [['shop_purchase_order_line_id' => $order->lines[0]->id, 'quantity' => '2']]]);
            $this->ids['rival:goods-receipt'] = $receipt->id;

            $counts = app(ManageStockCounts::class);
            $count = $counts->open($main, ['reason' => 'Rival count']);
            $counts->enter($count, [['item_id' => $item->id, 'counted_quantity' => '11']]);
            $this->ids['rival:stock-count'] = $counts->post($count)->id;

            $this->ids['rival:stock-transfer'] = app(TransferStock::class)->handle($main, $second, [['item_id' => $item->id, 'quantity' => '3']])->id;
        });
    }

    /** A rival work order with one of everything hanging off it, for the cross-organization probes. */
    private function rivalWorkOrder(string $organizationId, string $accountId, string $vehicleId, string $taskId): void
    {
        (new ApprovalSetting)->forceFill(['organization_id' => $organizationId, 'branch_id' => null] + ApprovalSettings::defaults()->toArray())->save();

        $order = new WorkOrder;
        $order->forceFill([
            'organization_id' => $organizationId,
            'branch_id' => $this->id('rival:branch'),
            'assigned_branch_id' => $this->id('rival:branch'),
            'customer_account_id' => $accountId,
            'vehicle_id' => $vehicleId,
            'reference' => 'WO-2026-0001',
            'title' => 'Rival oil change',
            'type' => 'preventive',
            'status' => 'scheduled',
            'opened_on' => '2026-10-01',
            'scheduled_for' => '2026-10-09',
            'scheduled_time' => '09:00',
            'bay_id' => $this->id('rival:bay'),
            'technician_id' => $this->id('rival:technician'),
            'technician_name' => 'Rival Mechanic',
        ])->save();
        $this->ids['rival:work-order'] = $order->id;

        $line = new WorkOrderLine;
        $line->forceFill([
            'organization_id' => $organizationId,
            'work_order_id' => $order->id,
            'service_task_id' => $taskId,
            'description' => 'Oil and filter',
            'category' => 'engine',
            'quantity' => '1',
            'unit_part_rate_cents' => 250000,
            'part_cost_cents' => 250000,
            'labour_hours' => '1',
            'labour_rate_cents' => 65000,
            'labour_cost_cents' => 65000,
            'urgency' => 'recommended',
            'parts_source' => 'supplier_provided',
            'approval_status' => 'approved',
            'approved_by_name' => 'Rival Fleet',
            'approved_at' => '2026-10-02T02:00:00Z',
        ])->save();
        $this->ids['rival:work-order-line'] = $line->id;

        $task = new WorkOrderTask;
        $task->forceFill(['organization_id' => $organizationId, 'work_order_id' => $order->id, 'service_task_id' => $taskId])->save();
        $this->ids['rival:work-order-task'] = $task->id;

        $part = new WorkOrderPart;
        $part->forceFill(['organization_id' => $organizationId, 'work_order_id' => $order->id, 'name' => 'Oil filter', 'quantity' => '1', 'unit_cost_cents' => 45000])->save();
        $this->ids['rival:work-order-part'] = $part->id;

        $event = new WorkOrderEvent;
        $event->forceFill(['organization_id' => $organizationId, 'work_order_id' => $order->id, 'status' => 'scheduled', 'at' => '2026-10-02T02:00:00Z', 'actor_name' => 'Rival Admin'])->save();
        $this->ids['rival:work-order-event'] = $event->id;

        $log = new ApprovalLogEntry;
        $log->forceFill(['organization_id' => $organizationId, 'work_order_id' => $order->id, 'line_id' => $line->id, 'action' => 'approved', 'actor_name' => 'Rival Fleet', 'at' => '2026-10-02T02:00:00Z', 'amount_at_time_cents' => 315000])->save();
        $this->ids['rival:approval-log'] = $log->id;
    }

    private function account(string $organizationId, string $name, string $type): CustomerAccount
    {
        $individual = $type === CustomerAccount::INDIVIDUAL;

        $account = new CustomerAccount;
        $account->forceFill([
            'organization_id' => $organizationId,
            'account_type' => $type,
            'display_name' => $name,
            'first_name' => $individual ? Str::before($name, ' ') : null,
            'last_name' => $individual ? Str::after($name, ' ') : null,
            'status' => 'active',
        ])->save();

        (new Consent)->forceFill([
            'organization_id' => $organizationId,
            'customer_account_id' => $account->id,
            'purpose' => ConsentPurpose::ServiceRecords,
            'granted' => true,
            'channel' => ConsentChannel::InPerson,
            'captured_at' => CarbonImmutable::now('UTC'),
        ])->save();

        return $account;
    }

    private function contact(CustomerAccount $account): Contact
    {
        $contact = new Contact;
        $contact->forceFill([
            'organization_id' => $account->organization_id,
            'customer_account_id' => $account->id,
            'name' => 'Contact of '.$account->display_name,
            'is_primary' => true,
        ])->save();

        (new Consent)->forceFill([
            'organization_id' => $account->organization_id,
            'customer_account_id' => $account->id,
            'contact_id' => $contact->id,
            'purpose' => ConsentPurpose::Marketing,
            'granted' => false,
            'channel' => ConsentChannel::Phone,
            'captured_at' => CarbonImmutable::now('UTC'),
        ])->save();

        return $contact;
    }

    private function makeUser(string $organizationId, string $email, Role $role, ?string $accountId): User
    {
        $user = new User;
        $user->forceFill([
            'organization_id' => $organizationId,
            'side' => $role->side(),
            'role' => $role,
            'customer_account_id' => $accountId,
            'name' => Str::headline(Str::before($email, '@')),
            'email' => $email,
            'password' => Hash::make(DemoSeeder::PASSWORD),
            'status' => User::ACTIVE,
        ])->save();

        return $user;
    }

    private function invitation(string $organizationId, string $email, Role $role, ?string $accountId, string $invitedBy): Invitation
    {
        $invitation = new Invitation;
        $invitation->forceFill([
            'organization_id' => $organizationId,
            'email' => $email,
            'name' => Str::headline(Str::before($email, '@')),
            'side' => $role->side(),
            'role' => $role,
            'customer_account_id' => $accountId,
            'token_hash' => Invitation::hashToken(Str::random(64)),
            'invited_by' => $invitedBy,
            'expires_at' => CarbonImmutable::now('UTC')->addDays(7),
        ])->save();

        return $invitation;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Access\Role;
use App\Domain\Crm\ConsentChannel;
use App\Domain\Crm\ConsentPurpose;
use App\Domain\Documents\DocumentKind;
use App\Domain\Maintenance\MeterKind;
use App\Domain\Modules\Module;
use App\Models\Bay;
use App\Models\Branch;
use App\Models\BranchModule;
use App\Models\Consent;
use App\Models\Contact;
use App\Models\CustomerAccount;
use App\Models\Document;
use App\Models\Invitation;
use App\Models\MaintenanceState;
use App\Models\MeterReading;
use App\Models\Organization;
use App\Models\OrganizationModule;
use App\Models\ServiceTask;
use App\Models\Technician;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleOwnership;
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
 * branch slugs, user emails) to the ULIDs created; extras are keyed
 * `walk-in`, `invite:*` and `rival*`.
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

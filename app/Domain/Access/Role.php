<?php

declare(strict_types=1);

namespace App\Domain\Access;

/**
 * Every role a user can hold. The eight ported roles keep the frontend's
 * values, labels and descriptions (golden-tested against rbac.json).
 * `branch_manager` and `cashier` are staff roles new in the API.
 *
 * "Owner" is not a separate role: the owner is a `provider_admin`.
 */
enum Role: string
{
    // Staff (the frontend's provider side).
    case ProviderAdmin = 'provider_admin';
    case ServiceAdvisor = 'service_advisor';
    case ProviderTechnician = 'provider_technician';
    case BranchManager = 'branch_manager';
    case Cashier = 'cashier';

    // Portal (the frontend's client side).
    case FleetManager = 'fleet_manager';
    case Operations = 'operations';
    case Technician = 'technician';
    case PurchasingOfficer = 'purchasing_officer';
    case Viewer = 'viewer';

    public function side(): Side
    {
        return match ($this) {
            self::ProviderAdmin, self::ServiceAdvisor, self::ProviderTechnician,
            self::BranchManager, self::Cashier => Side::Staff,
            self::FleetManager, self::Operations, self::Technician,
            self::PurchasingOfficer, self::Viewer => Side::Portal,
        };
    }

    public function isStaff(): bool
    {
        return $this->side() === Side::Staff;
    }

    /** True for the roles that exist only in the API, not in ../web. */
    public function isApiOnly(): bool
    {
        return $this === self::BranchManager || $this === self::Cashier;
    }

    public function label(): string
    {
        return match ($this) {
            self::ProviderAdmin => 'Provider Admin',
            self::ServiceAdvisor => 'Service Advisor',
            self::ProviderTechnician => 'Provider Technician',
            self::BranchManager => 'Branch Manager',
            self::Cashier => 'Cashier',
            self::FleetManager => 'Fleet Manager',
            self::Operations => 'Operations Staff',
            self::Technician => 'Technician',
            self::PurchasingOfficer => 'Purchasing Officer',
            self::Viewer => 'Authorised Viewer',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ProviderAdmin => 'Full access to the provider and every fleet client beneath it. The only role that can onboard clients or see across them.',
            self::ServiceAdvisor => 'Front of house across all clients: checks vehicles in and out, raises work orders, and sends quotations.',
            self::ProviderTechnician => 'Works assigned jobs across all clients: records findings and parts, and closes jobs. Cannot approve spend.',
            self::BranchManager => 'Runs the branches they are assigned to: their bays, technicians, staff and module settings. Cannot change the organization itself.',
            self::Cashier => 'Front counter: registers walk-in customers and records their consent. Point-of-sale grants arrive with the POS module.',
            self::FleetManager => 'Full control of their own fleet: schedules, work orders, documents, and settings. Unlimited approval authority within that one client. Cannot view or add users — only the provider admin manages accounts.',
            self::Operations => 'Raises and schedules work, logs readings, files documents, and approves purchases within threshold. Cannot change settings or access.',
            self::Technician => 'Works the bay: updates and closes jobs, records parts and findings, attaches reports.',
            self::PurchasingOfficer => 'Views everything and approves purchases within threshold, and issues purchase orders. Cannot edit PMS intervals or close work orders.',
            self::Viewer => 'Read-only. Sees every screen and can export nothing that changes state.',
        };
    }

    /**
     * @return list<self>
     */
    public static function staff(): array
    {
        return array_values(array_filter(self::cases(), fn (self $role): bool => $role->side() === Side::Staff));
    }

    /**
     * @return list<self>
     */
    public static function portal(): array
    {
        return array_values(array_filter(self::cases(), fn (self $role): bool => $role->side() === Side::Portal));
    }
}

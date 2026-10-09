<?php

declare(strict_types=1);

namespace App\Domain\Access;

/**
 * What a role may do. Ported from ../web/lib/rbac.ts `Capability`; the eleven
 * ported cases keep their exact values and labels (golden-tested against
 * rbac.json). `customer:manage` and `organization:manage` are new in the API.
 *
 * A capability never widens scope: a staff grant applies across the
 * organization because the tenant scope says so, not because of the grant.
 */
enum Capability: string
{
    case VehicleUpdate = 'vehicle:update';
    case VehicleManage = 'vehicle:manage';
    case WorkOrderCreate = 'workorder:create';
    case WorkOrderUpdate = 'workorder:update';
    case WorkOrderComplete = 'workorder:complete';
    case WorkOrderApprove = 'workorder:approve';
    case PoIssue = 'po:issue';
    case DocumentUpload = 'document:upload';
    case DocumentDelete = 'document:delete';
    case SettingsManage = 'settings:manage';
    case AccessManage = 'access:manage';
    // ------------------------------------------------------------- API only
    /** Create and edit customer accounts, their contacts, and record consent. */
    case CustomerManage = 'customer:manage';
    /** Organization-wide settings: profile, organization modules, opening and closing branches. */
    case OrganizationManage = 'organization:manage';
    /** See the shop's items, stock, movements, receipts, counts and transfers (staff only). */
    case InventoryView = 'inventory:view';
    /** Set up items, receive goods, count, adjust, transfer, raise purchase orders (staff only). */
    case InventoryManage = 'inventory:manage';

    /** Shown in denial reasons. The ported labels are verbatim from the frontend. */
    public function label(): string
    {
        return match ($this) {
            self::VehicleUpdate => 'Log odometer readings',
            self::VehicleManage => 'Add and edit vehicle records',
            self::WorkOrderCreate => 'Raise work orders',
            self::WorkOrderUpdate => 'Update job status',
            self::WorkOrderComplete => 'Close work orders',
            self::WorkOrderApprove => 'Approve purchases within threshold',
            self::PoIssue => 'Issue purchase orders',
            self::DocumentUpload => 'Upload documents',
            self::DocumentDelete => 'Delete documents',
            self::SettingsManage => 'Change settings & reset data',
            self::AccessManage => 'Manage user access',
            self::CustomerManage => 'Manage customer accounts',
            self::OrganizationManage => 'Manage the organization',
            self::InventoryView => 'View the shop inventory',
            self::InventoryManage => 'Manage the shop inventory',
        };
    }

    /** True for the capabilities that exist only in the API, not in ../web. */
    public function isApiOnly(): bool
    {
        return match ($this) {
            self::CustomerManage, self::OrganizationManage, self::InventoryView, self::InventoryManage => true,
            default => false,
        };
    }
}

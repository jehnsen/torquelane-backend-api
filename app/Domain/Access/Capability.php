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
    /** See invoices, payments, balances and statements (portal: their own account's; staff: aging and revenue too). */
    case BillingView = 'billing:view';
    /** Raise and issue invoices, record and allocate payments (staff only). */
    case BillingManage = 'billing:manage';
    /** Void an issued invoice or a payment (staff only). */
    case BillingVoid = 'billing:void';
    /** Read the chart of accounts, the journal and the accounting reports, within the caller's branches (staff only, Phase 8). */
    case LedgerView = 'ledger:view';
    /** Edit the chart, the posting rules and the export mappings; close accounting periods (staff only, whole organization). */
    case LedgerManage = 'ledger:manage';
    /** See a detailing branch's bookings, schedule, monitor, packages and settings (staff only, Phase 9). */
    case DetailingView = 'detailing:view';
    /** Take, edit, confirm and cancel bookings; name detailers; add extras; invoice a finished job (staff only). */
    case DetailingBook = 'detailing:book';
    /** Work a job card: inspect the vehicle, start, tick the checklist, complete (staff only). */
    case DetailingWork = 'detailing:work';
    /** Sign a finished job off, or send it back (staff only; never the detailer who worked it). */
    case DetailingQc = 'detailing:qc';
    /** Set up packages, prices, add-ons, materials and settings; block slots; issue display tokens (staff only). */
    case DetailingManage = 'detailing:manage';
    /** See a branch's equipment register and its monitor: service status, forecast, life, reliability, health (staff only, Phase 10). */
    case EquipmentView = 'equipment:view';
    /** Register and edit tools, log usage and services, map tools to packages (staff only). */
    case EquipmentManage = 'equipment:manage';

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
            self::BillingView => 'View invoices and payments',
            self::BillingManage => 'Invoice and record payments',
            self::BillingVoid => 'Void invoices and payments',
            self::LedgerView => 'View the books',
            self::LedgerManage => 'Manage the books and close periods',
            self::DetailingView => 'View detailing bookings',
            self::DetailingBook => 'Take and manage detailing bookings',
            self::DetailingWork => 'Work detailing job cards',
            self::DetailingQc => 'Sign off detailing jobs',
            self::DetailingManage => 'Manage detailing packages and settings',
            self::EquipmentView => 'View the equipment monitor',
            self::EquipmentManage => 'Manage equipment and its service history',
        };
    }

    /** True for the capabilities that exist only in the API, not in ../web. */
    public function isApiOnly(): bool
    {
        return match ($this) {
            self::CustomerManage, self::OrganizationManage, self::InventoryView, self::InventoryManage,
            self::BillingView, self::BillingManage, self::BillingVoid, self::LedgerView, self::LedgerManage,
            self::DetailingView, self::DetailingBook, self::DetailingWork, self::DetailingQc, self::DetailingManage,
            self::EquipmentView, self::EquipmentManage => true,
            default => false,
        };
    }
}

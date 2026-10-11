<?php

declare(strict_types=1);

use App\Tenancy\BelongsToOrganization;

/*
 * R5: every model is classified here. Adding a model without classifying it
 * fails this test.
 *
 *  - `tenant`: carries organization_id and uses BelongsToOrganization (its
 *    global scope + write guards). Asserted below.
 *  - `global`: carries no organization_id, with the reason. This is the
 *    explicit allowlist ArchTest's trait rule refers to (GLOBAL_MODELS).
 */
const MODEL_TENANCY = [
    'App\Models\AlertInteraction' => ['tenant', ''],
    'App\Models\ApprovalLogEntry' => ['tenant', ''],
    'App\Models\ApprovalSetting' => ['tenant', ''],
    'App\Models\AuditLog' => ['tenant', ''],
    'App\Models\Bay' => ['tenant', ''],
    'App\Models\Branch' => ['tenant', ''],
    'App\Models\BranchModule' => ['tenant', ''],
    'App\Models\Consent' => ['tenant', ''],
    'App\Models\Contact' => ['tenant', ''],
    'App\Models\CustomerAccount' => ['tenant', ''],
    // Phase 9: detailing. Every one is tenant-owned and staff-only.
    'App\Models\DetailingAddon' => ['tenant', ''],
    'App\Models\DetailingAddonPrice' => ['tenant', ''],
    'App\Models\DetailingBlockedSlot' => ['tenant', ''],
    'App\Models\DetailingBooking' => ['tenant', ''],
    'App\Models\DetailingBookingAddon' => ['tenant', ''],
    'App\Models\DetailingBookingDetailer' => ['tenant', ''],
    'App\Models\DetailingBookingSlot' => ['tenant', ''],
    'App\Models\DetailingBookingStatusLog' => ['tenant', ''],
    'App\Models\DetailingCategory' => ['tenant', ''],
    'App\Models\DetailingDamagePoint' => ['tenant', ''],
    'App\Models\DetailingDisplayToken' => ['tenant', ''],
    'App\Models\DetailingInspection' => ['tenant', ''],
    'App\Models\DetailingInspectionPhoto' => ['tenant', ''],
    'App\Models\DetailingPackage' => ['tenant', ''],
    'App\Models\DetailingPackageMaterial' => ['tenant', ''],
    'App\Models\DetailingPackagePrice' => ['tenant', ''],
    'App\Models\DetailingRecurringSchedule' => ['tenant', ''],
    'App\Models\DetailingSetting' => ['tenant', ''],
    'App\Models\InvoiceBooking' => ['tenant', ''],
    // Phase 11: the CRM. Every one is tenant-owned and staff-only.
    'App\Models\CrmCampaign' => ['tenant', ''],
    'App\Models\CrmLead' => ['tenant', ''],
    'App\Models\CrmNote' => ['tenant', ''],
    'App\Models\CrmSegment' => ['tenant', ''],
    'App\Models\CrmSetting' => ['tenant', ''],
    'App\Models\CrmTask' => ['tenant', ''],
    'App\Models\FeedbackRequest' => ['tenant', ''],
    'App\Models\LoyaltyEntry' => ['tenant', ''],
    'App\Models\Membership' => ['tenant', ''],
    'App\Models\MembershipTier' => ['tenant', ''],
    'App\Models\Message' => ['tenant', ''],
    'App\Models\MessageSuppression' => ['tenant', ''],
    'App\Models\MessageTemplate' => ['tenant', ''],
    'App\Models\PrepaidPackage' => ['tenant', ''],
    'App\Models\PrepaidPlan' => ['tenant', ''],
    'App\Models\PrepaidRedemption' => ['tenant', ''],
    'App\Models\ReminderRule' => ['tenant', ''],
    // Phase 10: equipment monitoring.
    'App\Models\Equipment' => ['tenant', ''],
    'App\Models\EquipmentMaintenanceLog' => ['tenant', ''],
    'App\Models\EquipmentMaintenancePart' => ['tenant', ''],
    'App\Models\EquipmentPackageProfile' => ['tenant', ''],
    'App\Models\EquipmentUsageLog' => ['tenant', ''],
    'App\Models\Document' => ['tenant', ''],
    'App\Models\FleetPart' => ['tenant', ''],
    'App\Models\FleetPartUsage' => ['tenant', ''],
    'App\Models\DocumentSeries' => ['tenant', ''],
    'App\Models\IdempotencyKey' => ['global', 'Infrastructure keyed per user, pruned after 24h; holds no business data.'],
    'App\Models\Invitation' => ['tenant', ''],
    'App\Models\Invoice' => ['tenant', ''],
    'App\Models\InvoiceLine' => ['tenant', ''],
    'App\Models\InvoiceWorkOrder' => ['tenant', ''],
    'App\Models\Payment' => ['tenant', ''],
    'App\Models\PaymentAllocation' => ['tenant', ''],
    'App\Models\Account' => ['tenant', ''],
    'App\Models\PostingRule' => ['tenant', ''],
    'App\Models\Period' => ['tenant', ''],
    'App\Models\JournalEntry' => ['tenant', ''],
    'App\Models\JournalLine' => ['tenant', ''],
    'App\Models\AccountExportMapping' => ['tenant', ''],
    'App\Models\MaintenanceState' => ['tenant', ''],
    'App\Models\MeterReading' => ['tenant', ''],
    'App\Models\Organization' => ['global', 'The tenant root itself: always loaded by the session\'s own organization_id, never listed.'],
    'App\Models\OrganizationModule' => ['tenant', ''],
    'App\Models\PurchaseOrder' => ['tenant', ''],
    'App\Models\PurchaseOrderEvent' => ['tenant', ''],
    'App\Models\PurchaseOrderLine' => ['tenant', ''],
    'App\Models\ServiceTask' => ['tenant', ''],
    'App\Models\Technician' => ['tenant', ''],
    'App\Models\GoodsReceipt' => ['tenant', ''],
    'App\Models\GoodsReceiptLine' => ['tenant', ''],
    'App\Models\Item' => ['tenant', ''],
    'App\Models\ItemBranchSetting' => ['tenant', ''],
    'App\Models\ShopPurchaseOrder' => ['tenant', ''],
    'App\Models\ShopPurchaseOrderEvent' => ['tenant', ''],
    'App\Models\ShopPurchaseOrderLine' => ['tenant', ''],
    'App\Models\StockBalance' => ['tenant', ''],
    'App\Models\StockCount' => ['tenant', ''],
    'App\Models\StockCountLine' => ['tenant', ''],
    'App\Models\StockLocation' => ['tenant', ''],
    'App\Models\StockMove' => ['tenant', ''],
    'App\Models\StockTransfer' => ['tenant', ''],
    'App\Models\StockTransferLine' => ['tenant', ''],
    'App\Models\User' => ['tenant', ''],
    'App\Models\Vehicle' => ['tenant', ''],
    'App\Models\VehicleOwnership' => ['tenant', ''],
    'App\Models\Vendor' => ['tenant', ''],
    'App\Models\WorkOrder' => ['tenant', ''],
    'App\Models\WorkOrderEvent' => ['tenant', ''],
    'App\Models\WorkOrderLine' => ['tenant', ''],
    'App\Models\WorkOrderPart' => ['tenant', ''],
    'App\Models\WorkOrderTask' => ['tenant', ''],
];

it('classifies every model in app/Models', function () {
    $models = collect(glob(dirname(__DIR__, 2).'/app/Models/*.php') ?: [])
        ->map(fn (string $path) => 'App\\Models\\'.basename($path, '.php'))
        ->sort()
        ->values()
        ->all();

    $classified = array_keys(MODEL_TENANCY);
    sort($classified);

    expect($models)->toBe($classified);
});

it('gives a reason for every global model', function () {
    foreach (MODEL_TENANCY as $model => [$kind, $reason]) {
        expect($kind)->toBeIn(['global', 'tenant'])
            ->and($kind === 'global' ? $reason : 'n/a')->not->toBeEmpty("{$model} needs a reason");
    }
});

it('puts every tenant model behind BelongsToOrganization, and no global one', function () {
    foreach (MODEL_TENANCY as $model => [$kind]) {
        $uses = in_array(BelongsToOrganization::class, class_uses_recursive($model), true);

        expect($uses)->toBe($kind === 'tenant', "{$model} is classified {$kind}");
    }
});

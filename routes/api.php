<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AlertController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\ApprovalRequestsController;
use App\Http\Controllers\Api\V1\ApprovalSettingsController;
use App\Http\Controllers\Api\V1\Auth\InvitationAcceptanceController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\BayController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\CheckInController;
use App\Http\Controllers\Api\V1\ConsentController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\CustomerAccountController;
use App\Http\Controllers\Api\V1\DemandForecastController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\DocumentFileController;
use App\Http\Controllers\Api\V1\FleetPartController;
use App\Http\Controllers\Api\V1\FleetSummaryController;
use App\Http\Controllers\Api\V1\GoodsReceiptController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\ItemController;
use App\Http\Controllers\Api\V1\MeterReadingController;
use App\Http\Controllers\Api\V1\ModuleController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\PurchaseOrderController;
use App\Http\Controllers\Api\V1\ReceivablesController;
use App\Http\Controllers\Api\V1\ServiceTaskController;
use App\Http\Controllers\Api\V1\ShopController;
use App\Http\Controllers\Api\V1\ShopPurchaseOrderController;
use App\Http\Controllers\Api\V1\StockController;
use App\Http\Controllers\Api\V1\StockCountController;
use App\Http\Controllers\Api\V1\StockTransferController;
use App\Http\Controllers\Api\V1\TechnicianController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\VehicleController;
use App\Http\Controllers\Api\V1\VendorController;
use App\Http\Controllers\Api\V1\WorkOrderController;
use Illuminate\Support\Facades\Route;

/*
 * Every route here is served under /api/v1 (apiPrefix in bootstrap/app.php).
 *
 * Tenant data: ->middleware(['auth:sanctum', 'tenant']). `tenant` resolves the
 * TenantContext from the signed-in user before route-model binding, so a
 * bound {model} from another organization is 404. For POSTs a client may
 * retry, add 'idempotent'. Every new GET route must be listed in
 * tests/Isolation/coverage.php; the isolation suite then probes it for every
 * demo user automatically (R5).
 */

Route::get('health', HealthController::class)->name('health');

// ---------------------------------------------------------------- auth
Route::prefix('auth')->group(function (): void {
    Route::post('login', [SessionController::class, 'login'])->middleware('throttle:login')->name('auth.login');
    Route::post('logout', [SessionController::class, 'logout'])->middleware('auth:sanctum')->name('auth.logout');
    Route::post('forgot-password', [PasswordController::class, 'forgot'])->middleware('throttle:password-reset')->name('auth.forgot-password');
    Route::post('reset-password', [PasswordController::class, 'reset'])->middleware('throttle:password-reset')->name('auth.reset-password');
    Route::post('invitations/accept', InvitationAcceptanceController::class)->middleware('throttle:password-reset')->name('auth.invitations.accept');
});

// --------------------------------------------------------- tenant data
Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::get('me', [SessionController::class, 'me'])->name('me');
    Route::patch('me', [ProfileController::class, 'update'])->name('me.update');
    Route::put('me/password', [ProfileController::class, 'password'])->middleware('throttle:password-reset')->name('me.password');

    Route::get('organization', [OrganizationController::class, 'show'])->name('organization.show');
    Route::patch('organization', [OrganizationController::class, 'update'])->name('organization.update');

    Route::apiResource('branches', BranchController::class);

    Route::get('modules', [ModuleController::class, 'index'])->name('modules.index');
    Route::put('modules/{module}', [ModuleController::class, 'updateOrganization'])->name('modules.update');
    Route::put('branches/{branch}/modules/{module}', [ModuleController::class, 'updateBranch'])->name('branches.modules.update');

    Route::apiResource('users', UserController::class)->only(['index', 'show', 'update']);

    Route::apiResource('invitations', InvitationController::class)->only(['index', 'destroy']);
    Route::post('invitations', [InvitationController::class, 'store'])->middleware('idempotent')->name('invitations.store');

    Route::apiResource('customer-accounts', CustomerAccountController::class)->except(['store', 'destroy']);
    Route::post('customer-accounts', [CustomerAccountController::class, 'store'])->middleware('idempotent')->name('customer-accounts.store');
    Route::post('customer-accounts/{customer_account}/suspend', [CustomerAccountController::class, 'suspend'])->name('customer-accounts.suspend');
    Route::post('customer-accounts/{customer_account}/reactivate', [CustomerAccountController::class, 'reactivate'])->name('customer-accounts.reactivate');

    Route::apiResource('customer-accounts.contacts', ContactController::class)->scoped();

    Route::get('customer-accounts/{customer_account}/consents', [ConsentController::class, 'index'])->name('customer-accounts.consents.index');
    Route::get('customer-accounts/{customer_account}/consents/current', [ConsentController::class, 'current'])->name('customer-accounts.consents.current');
    Route::post('customer-accounts/{customer_account}/consents', [ConsentController::class, 'store'])->middleware('idempotent')->name('customer-accounts.consents.store');

    Route::apiResource('bays', BayController::class);
    Route::apiResource('technicians', TechnicianController::class);

    // ------------------------------------------------------------ fleet
    Route::apiResource('vehicles', VehicleController::class)->except(['store']);
    Route::post('vehicles', [VehicleController::class, 'store'])->middleware('idempotent')->name('vehicles.store');
    Route::post('vehicles/{vehicle}/transfer', [VehicleController::class, 'transfer'])->name('vehicles.transfer');
    Route::get('vehicles/{vehicle}/ownerships', [VehicleController::class, 'ownerships'])->name('vehicles.ownerships');
    Route::get('vehicles/{vehicle}/health', [VehicleController::class, 'health'])->name('vehicles.health');
    Route::get('vehicles/{vehicle}/readings', [MeterReadingController::class, 'index'])->name('vehicles.readings.index');
    Route::post('vehicles/{vehicle}/readings', [MeterReadingController::class, 'store'])->middleware('idempotent')->name('vehicles.readings.store');
    Route::post('vehicles/{vehicle}/readings/{reading}/void', [MeterReadingController::class, 'void'])->scopeBindings()->name('vehicles.readings.void');

    Route::get('fleet/summary', FleetSummaryController::class)->name('fleet.summary');
    Route::apiResource('service-tasks', ServiceTaskController::class);

    Route::get('documents/summary', [DocumentController::class, 'summary'])->name('documents.summary');
    Route::apiResource('documents', DocumentController::class)->except(['store', 'update']);
    Route::post('documents', [DocumentController::class, 'store'])->middleware('idempotent')->name('documents.store');
    Route::get('documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');

    Route::get('alerts', [AlertController::class, 'index'])->name('alerts.index');
    Route::post('alerts/read', [AlertController::class, 'read'])->name('alerts.read');
    Route::post('alerts/dismiss', [AlertController::class, 'dismiss'])->name('alerts.dismiss');
    Route::post('alerts/restore', [AlertController::class, 'restore'])->name('alerts.restore');

    // Repair: work orders, approvals, check-in, the shop floor (Phase 3).
    Route::get('approval-settings', [ApprovalSettingsController::class, 'show'])->name('approval-settings.show');
    Route::put('approval-settings', [ApprovalSettingsController::class, 'updateOrganization'])->name('approval-settings.update');
    Route::put('branches/{branch}/approval-settings', [ApprovalSettingsController::class, 'updateBranch'])->name('branches.approval-settings.update');
    Route::get('customer-accounts/{customer_account}/approval-settings', [ApprovalSettingsController::class, 'showAccount'])->name('customer-accounts.approval-settings.show');
    Route::patch('customer-accounts/{customer_account}/approval-settings', [ApprovalSettingsController::class, 'updateAccount'])->name('customer-accounts.approval-settings.update');

    Route::get('work-orders', [WorkOrderController::class, 'index'])->name('work-orders.index');
    Route::get('work-orders/summary', [WorkOrderController::class, 'summary'])->name('work-orders.summary');
    Route::post('work-orders', [WorkOrderController::class, 'store'])->middleware('idempotent')->name('work-orders.store');
    Route::post('work-orders/collect', [WorkOrderController::class, 'collectMany'])->name('work-orders.collect-many');
    Route::post('work-orders/auto-schedule', [WorkOrderController::class, 'autoSchedule'])->name('work-orders.auto-schedule');
    Route::get('work-orders/{work_order}', [WorkOrderController::class, 'show'])->name('work-orders.show');
    Route::patch('work-orders/{work_order}', [WorkOrderController::class, 'update'])->name('work-orders.update');
    Route::put('work-orders/{work_order}/lines', [WorkOrderController::class, 'lines'])->name('work-orders.lines');
    Route::post('work-orders/{work_order}/send', [WorkOrderController::class, 'send'])->name('work-orders.send');
    Route::post('work-orders/{work_order}/decisions', [WorkOrderController::class, 'decide'])->name('work-orders.decide');
    Route::post('work-orders/{work_order}/schedule', [WorkOrderController::class, 'schedule'])->name('work-orders.schedule');
    Route::post('work-orders/{work_order}/start', [WorkOrderController::class, 'start'])->name('work-orders.start');
    Route::post('work-orders/{work_order}/complete', [WorkOrderController::class, 'complete'])->name('work-orders.complete');
    Route::post('work-orders/{work_order}/close', [WorkOrderController::class, 'close'])->name('work-orders.close');
    Route::post('work-orders/{work_order}/collect', [WorkOrderController::class, 'collect'])->name('work-orders.collect');
    Route::post('work-orders/{work_order}/cancel', [WorkOrderController::class, 'cancel'])->name('work-orders.cancel');

    Route::get('check-in/lookup', [CheckInController::class, 'lookup'])->name('check-in.lookup');
    Route::post('check-in', [CheckInController::class, 'store'])->middleware('idempotent')->name('check-in.store');

    Route::prefix('shop')->name('shop.')->group(function (): void {
        Route::get('arriving', [ShopController::class, 'arriving'])->name('arriving');
        Route::get('in-progress', [ShopController::class, 'inProgress'])->name('in-progress');
        Route::get('ready-for-collection', [ShopController::class, 'readyForCollection'])->name('ready-for-collection');
        Route::get('approvals', [ShopController::class, 'approvals'])->name('approvals');
        Route::get('floor', [ShopController::class, 'floor'])->name('floor');
        Route::get('technicians', [ShopController::class, 'technicians'])->name('technicians');
        Route::get('revenue', [ShopController::class, 'revenue'])->name('revenue');
        Route::get('home', [ShopController::class, 'home'])->name('home');
        Route::get('reports', [ShopController::class, 'reports'])->name('reports');
        Route::get('clients', [ShopController::class, 'clients'])->name('clients');
        Route::get('clients/{customer_account}', [ShopController::class, 'client'])->name('clients.show');
    });

    // Purpose-built reads for the fleet screens (Phase 4).
    Route::prefix('analytics')->name('analytics.')->group(function (): void {
        Route::get('dashboard', [AnalyticsController::class, 'dashboard'])->name('dashboard');
        Route::get('schedule', [AnalyticsController::class, 'schedule'])->name('schedule');
        Route::get('reports', [AnalyticsController::class, 'reports'])->name('reports');
        Route::get('auto-schedule', [AnalyticsController::class, 'autoSchedule'])->name('auto-schedule');
    });
    Route::get('requests', ApprovalRequestsController::class)->name('requests');

    // Parts and purchasing (Phase 4): the provider's vendors, each customer
    // account's own spare parts, the demand forecast, purchase orders.
    Route::apiResource('vendors', VendorController::class);
    Route::apiResource('fleet-parts', FleetPartController::class);
    Route::get('demand-forecast', DemandForecastController::class)->name('demand-forecast');

    Route::get('purchase-orders', [PurchaseOrderController::class, 'index'])->name('purchase-orders.index');
    Route::post('purchase-orders', [PurchaseOrderController::class, 'store'])->middleware('idempotent')->name('purchase-orders.store');
    Route::get('purchase-orders/export', [PurchaseOrderController::class, 'export'])->name('purchase-orders.export');
    Route::get('purchase-orders/{purchase_order}', [PurchaseOrderController::class, 'show'])->name('purchase-orders.show');
    Route::get('purchase-orders/{purchase_order}/export', [PurchaseOrderController::class, 'exportOne'])->name('purchase-orders.export-one');
    Route::post('purchase-orders/{purchase_order}/send', [PurchaseOrderController::class, 'send'])->name('purchase-orders.send');
    Route::post('purchase-orders/{purchase_order}/receive', [PurchaseOrderController::class, 'receive'])->name('purchase-orders.receive');
    Route::post('purchase-orders/{purchase_order}/cancel', [PurchaseOrderController::class, 'cancel'])->name('purchase-orders.cancel');

    // The shop's own inventory (Phase 6): items, stock, the movement ledger,
    // purchase orders and goods receipts, counts, transfers. Staff only.
    Route::get('items', [ItemController::class, 'index'])->name('items.index');
    Route::post('items', [ItemController::class, 'store'])->middleware('idempotent')->name('items.store');
    Route::get('items/{item}', [ItemController::class, 'show'])->name('items.show');
    Route::patch('items/{item}', [ItemController::class, 'update'])->name('items.update');
    Route::put('items/{item}/branch-settings/{branch}', [ItemController::class, 'branchSettings'])->name('items.branch-settings');

    Route::get('stock-locations', [StockController::class, 'locations'])->name('stock-locations.index');
    Route::prefix('stock')->name('stock.')->group(function (): void {
        Route::get('on-hand', [StockController::class, 'onHand'])->name('on-hand');
        Route::get('moves', [StockController::class, 'moves'])->name('moves');
        Route::get('alerts', [StockController::class, 'alerts'])->name('alerts');
        Route::get('reorder', [StockController::class, 'reorder'])->name('reorder');
        Route::post('opening', [StockController::class, 'opening'])->middleware('idempotent')->name('opening');
    });

    Route::get('shop-purchase-orders', [ShopPurchaseOrderController::class, 'index'])->name('shop-purchase-orders.index');
    Route::post('shop-purchase-orders', [ShopPurchaseOrderController::class, 'store'])->middleware('idempotent')->name('shop-purchase-orders.store');
    Route::get('shop-purchase-orders/{shop_purchase_order}', [ShopPurchaseOrderController::class, 'show'])->name('shop-purchase-orders.show');
    Route::patch('shop-purchase-orders/{shop_purchase_order}', [ShopPurchaseOrderController::class, 'update'])->name('shop-purchase-orders.update');
    Route::post('shop-purchase-orders/{shop_purchase_order}/issue', [ShopPurchaseOrderController::class, 'issue'])->name('shop-purchase-orders.issue');
    Route::post('shop-purchase-orders/{shop_purchase_order}/cancel', [ShopPurchaseOrderController::class, 'cancel'])->name('shop-purchase-orders.cancel');
    Route::post('shop-purchase-orders/{shop_purchase_order}/receipts', [ShopPurchaseOrderController::class, 'receive'])->middleware('idempotent')->name('shop-purchase-orders.receive');

    Route::get('goods-receipts', [GoodsReceiptController::class, 'index'])->name('goods-receipts.index');
    Route::get('goods-receipts/{goods_receipt}', [GoodsReceiptController::class, 'show'])->name('goods-receipts.show');
    Route::post('goods-receipts/{goods_receipt}/void', [GoodsReceiptController::class, 'void'])->name('goods-receipts.void');

    Route::get('stock-counts', [StockCountController::class, 'index'])->name('stock-counts.index');
    Route::post('stock-counts', [StockCountController::class, 'store'])->middleware('idempotent')->name('stock-counts.store');
    Route::get('stock-counts/{stock_count}', [StockCountController::class, 'show'])->name('stock-counts.show');
    Route::put('stock-counts/{stock_count}/lines', [StockCountController::class, 'enter'])->name('stock-counts.lines');
    Route::post('stock-counts/{stock_count}/post', [StockCountController::class, 'post'])->name('stock-counts.post');
    Route::post('stock-counts/{stock_count}/cancel', [StockCountController::class, 'cancel'])->name('stock-counts.cancel');

    Route::get('stock-transfers', [StockTransferController::class, 'index'])->name('stock-transfers.index');
    Route::post('stock-transfers', [StockTransferController::class, 'store'])->middleware('idempotent')->name('stock-transfers.store');
    Route::get('stock-transfers/{stock_transfer}', [StockTransferController::class, 'show'])->name('stock-transfers.show');
    Route::post('stock-transfers/{stock_transfer}/reverse', [StockTransferController::class, 'reverse'])->name('stock-transfers.reverse');

    // Order-to-cash (Phase 7): invoices, payments, receivables. Core: no module.
    Route::get('billing/queue', [ReceivablesController::class, 'queue'])->name('billing.queue');
    Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::post('invoices', [InvoiceController::class, 'store'])->middleware('idempotent')->name('invoices.store');
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::patch('invoices/{invoice}', [InvoiceController::class, 'update'])->name('invoices.update');
    Route::delete('invoices/{invoice}', [InvoiceController::class, 'destroy'])->name('invoices.destroy');
    Route::post('invoices/{invoice}/issue', [InvoiceController::class, 'issue'])->middleware('idempotent:required')->name('invoices.issue');
    Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void'])->name('invoices.void');
    Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');

    Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
    Route::post('payments', [PaymentController::class, 'store'])->middleware('idempotent:required')->name('payments.store');
    Route::get('payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
    Route::post('payments/{payment}/allocations', [PaymentController::class, 'allocate'])->middleware('idempotent')->name('payments.allocate');
    Route::post('payments/{payment}/void', [PaymentController::class, 'void'])->name('payments.void');
    Route::get('payments/{payment}/pdf', [PaymentController::class, 'pdf'])->name('payments.pdf');

    Route::get('receivables/aging', [ReceivablesController::class, 'aging'])->name('receivables.aging');
    Route::get('receivables/revenue', [ReceivablesController::class, 'revenue'])->name('receivables.revenue');
    Route::get('customer-accounts/{customer_account}/balance', [ReceivablesController::class, 'balance'])->name('customer-accounts.balance');
    Route::get('customer-accounts/{customer_account}/statement', [ReceivablesController::class, 'statement'])->name('customer-accounts.statement');
    Route::get('customer-accounts/{customer_account}/statement/pdf', [ReceivablesController::class, 'statementPdf'])->name('customer-accounts.statement-pdf');
});

// A signed, 60-second URL from GET documents/{id}/download: the signature is
// the credential, so no session (and no tenant middleware).
Route::get('document-files/{document}', DocumentFileController::class)->middleware('signed')->name('document-files.show');

<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AlertController;
use App\Http\Controllers\Api\V1\Auth\InvitationAcceptanceController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\BayController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\ConsentController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\CustomerAccountController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\DocumentFileController;
use App\Http\Controllers\Api\V1\FleetSummaryController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\MeterReadingController;
use App\Http\Controllers\Api\V1\ModuleController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\ServiceTaskController;
use App\Http\Controllers\Api\V1\TechnicianController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\VehicleController;
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

    Route::apiResource('documents', DocumentController::class)->except(['store', 'update']);
    Route::post('documents', [DocumentController::class, 'store'])->middleware('idempotent')->name('documents.store');
    Route::get('documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');

    Route::get('alerts', [AlertController::class, 'index'])->name('alerts.index');
    Route::post('alerts/read', [AlertController::class, 'read'])->name('alerts.read');
    Route::post('alerts/dismiss', [AlertController::class, 'dismiss'])->name('alerts.dismiss');
    Route::post('alerts/restore', [AlertController::class, 'restore'])->name('alerts.restore');
});

// A signed, 60-second URL from GET documents/{id}/download: the signature is
// the credential, so no session (and no tenant middleware).
Route::get('document-files/{document}', DocumentFileController::class)->middleware('signed')->name('document-files.show');

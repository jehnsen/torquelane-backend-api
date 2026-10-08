<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

/*
 * Every route here is served under /api/v1 (apiPrefix in bootstrap/app.php).
 *
 * Writes: ->middleware(['auth:sanctum', <tenant middleware, Phase 1>]) and,
 * for POSTs a client may retry, 'idempotent'. Every new GET route must be
 * listed in tests/Isolation/coverage.php (R5).
 */

Route::get('health', HealthController::class)->name('health');

<?php

declare(strict_types=1);

/*
 * Child process for BookingConcurrencyTest. Takes detailing bookings through
 * the real action from its own connection, so several of these genuinely race
 * for the same slots.
 *
 *   php take-bookings.php <database> <branch_id> <account_id> <package_id> <date> <start_minutes> <attempts> <seed> [detailer_id]
 *
 * Each attempt is its own transaction, held open a moment after the decision
 * so the others queue behind its locks. Prints `ok` for a booking that
 * committed, or the reason the engine refused it (`full`, `detailer_conflict`).
 */

use App\Actions\Detailing\TakeBooking;
use App\Domain\Detailing\SizeClass;
use App\Exceptions\ConflictException;
use App\Models\Branch;
use App\Models\CustomerAccount;
use App\Models\DetailingPackage;
use App\Tenancy\TenantManager;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $database, $branchId, $accountId, $packageId, $date, $startMinutes, $attempts, $seed] = $argv;
$detailerId = $argv[9] ?? null;

config(['database.connections.pgsql.database' => $database]);
DB::purge('pgsql');

try {
    mt_srand((int) $seed);
    for ($i = 0; $i < (int) $attempts; $i++) {
        try {
            app(TenantManager::class)->system('booking concurrency test', function () use ($branchId, $accountId, $packageId, $date, $startMinutes, $detailerId): void {
                DB::transaction(function () use ($branchId, $accountId, $packageId, $date, $startMinutes, $detailerId): void {
                    app(TakeBooking::class)->handle(
                        Branch::query()->findOrFail($branchId),
                        CustomerAccount::query()->findOrFail($accountId),
                        null,
                        DetailingPackage::query()->findOrFail($packageId),
                        SizeClass::Medium,
                        ['date' => $date, 'start_minutes' => (int) $startMinutes, 'detailer_ids' => $detailerId === null ? [] : [$detailerId]],
                    );
                    // Hold the locks a moment so the processes genuinely contend.
                    usleep(mt_rand(0, 3000));
                });
            });
            echo 'ok', PHP_EOL;
        } catch (ConflictException $e) {
            $reason = $e->details()['reason'] ?? 'conflict';
            echo is_string($reason) ? $reason : 'conflict', PHP_EOL;
        }
    }
} catch (Throwable $e) {
    // Laravel's handler would print this and still exit 0: fail loudly so the parent sees it.
    fwrite(STDERR, $e::class.': '.$e->getMessage().PHP_EOL);
    exit(1);
}

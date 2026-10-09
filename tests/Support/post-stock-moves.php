<?php

declare(strict_types=1);

/*
 * Child process for LedgerConcurrencyTest. Posts stock moves through the real
 * ledger service from its own connection, so several of these genuinely race
 * on one balance row.
 *
 *   php post-stock-moves.php open  <database> <location_id> <item_id> <quantity> <unit_cost_cents>
 *   php post-stock-moves.php issue <database> <location_id> <item_id> <attempts> <seed>
 *
 * `issue` takes one unit per attempt, in its own transaction, sometimes
 * holding the lock a moment so the others queue behind it; it prints `ok` for
 * a move that committed and `blocked` for one the branch's policy refused.
 */

use App\Actions\Inventory\PostStockMove;
use App\Domain\Inventory\MoveRequest;
use App\Domain\Inventory\MoveType;
use App\Domain\Inventory\StockSource;
use App\Exceptions\ConflictException;
use App\Models\StockLocation;
use App\Tenancy\TenantManager;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $mode, $database, $locationId, $itemId, $argument, $extra] = $argv;

config(['database.connections.pgsql.database' => $database]);
DB::purge('pgsql');

$tenancy = app(TenantManager::class);
$post = function (MoveRequest $move) use ($tenancy, $locationId, $itemId): void {
    $tenancy->system('ledger concurrency test', function () use ($move, $locationId, $itemId): void {
        DB::transaction(function () use ($move, $locationId, $itemId): void {
            $location = StockLocation::query()->findOrFail($locationId);
            app(PostStockMove::class)->handle($location, $itemId, $move, StockSource::Manual, null, 'concurrency test');
            // Hold the row lock a moment so the processes genuinely contend.
            usleep(mt_rand(0, 2000));
        });
    });
};

try {
    if ($mode === 'open') {
        $post(new MoveRequest(MoveType::Opening, $argument, (int) $extra));

        return;
    }

    mt_srand((int) $extra);
    for ($i = 0; $i < (int) $argument; $i++) {
        try {
            $post(new MoveRequest(MoveType::Issue, '-1'));
            echo 'ok', PHP_EOL;
        } catch (ConflictException) {
            echo 'blocked', PHP_EOL;
        }
    }
} catch (Throwable $e) {
    // Laravel's handler would print this and still exit 0: fail loudly so the parent sees it.
    fwrite(STDERR, $e::class.': '.$e->getMessage().PHP_EOL);
    exit(1);
}

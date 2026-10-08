<?php

declare(strict_types=1);

/*
 * Child process for DocumentNumbersTest's concurrency case. Issues numbers
 * from one series in its own transactions, rolling back roughly one in three,
 * and prints the numbers whose transactions COMMITTED, one per line.
 *
 * Usage: php issue-numbers.php <database> <organization_id> <iterations> <seed>
 */

use App\Actions\Numbering\DocumentNumbers;
use App\Domain\Numbering\DocumentType;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $database, $organizationId, $iterations, $seed] = $argv;

config(['database.connections.pgsql.database' => $database]);
DB::purge('pgsql');
mt_srand((int) $seed);

$tenancy = app(TenantManager::class);

for ($i = 0; $i < (int) $iterations; $i++) {
    $rollBack = mt_rand(0, 2) === 0;

    try {
        $number = DB::transaction(function () use ($tenancy, $organizationId, $rollBack): int {
            $issued = $tenancy->system('numbering concurrency test', fn () => app(DocumentNumbers::class)
                ->issue($organizationId, null, DocumentType::Invoice, CarbonImmutable::now('UTC')));

            // Hold the row lock a moment so the processes genuinely contend.
            usleep(mt_rand(0, 3000));

            if ($rollBack) {
                throw new RuntimeException('roll back');
            }

            return $issued->number;
        });

        echo $number, PHP_EOL;
    } catch (RuntimeException) {
        // Rolled back: the number must be reissued, never skipped.
    }
}

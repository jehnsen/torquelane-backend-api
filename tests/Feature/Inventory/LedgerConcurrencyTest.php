<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Several processes issuing from one balance at once must leave it exactly
 * as one process doing the same work in turn would: no lost update, no
 * move without its balance, no balance without its moves. The ledger locks
 * the balance row; these tests prove the lock is there by racing real
 * processes (autocommit connections: the rows must be committed for a child
 * to see them, so the test builds its own organization and removes it after).
 */

/**
 * @return array{db: Connection, org: string, location: string, item: string}
 */
function concurrencyWorld(string $policy): array
{
    config(['database.connections.ledger' => config('database.connections.pgsql')]);
    $db = DB::connection('ledger');
    $ulid = fn (): string => strtolower((string) Str::ulid());
    $now = now();
    $org = $ulid();
    $branch = $ulid();
    $location = $ulid();
    $item = $ulid();

    $db->table('organizations')->insert(['id' => $org, 'name' => 'Ledger Race', 'slug' => 'race-'.$org, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
    $db->table('branches')->insert(['id' => $branch, 'organization_id' => $org, 'name' => 'Race Main', 'slug' => 'main-'.$branch, 'is_vat_registered' => true, 'prices_include_vat' => true, 'timezone' => 'Asia/Manila', 'status' => 'active', 'negative_stock_policy' => $policy, 'created_at' => $now, 'updated_at' => $now]);
    $db->table('stock_locations')->insert(['id' => $location, 'organization_id' => $org, 'branch_id' => $branch, 'kind' => 'store', 'name' => 'Race store', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
    $db->table('items')->insert(['id' => $item, 'organization_id' => $org, 'sku' => 'RACE-1', 'name' => 'Race item', 'item_type' => 'part', 'uom' => 'pc', 'created_at' => $now, 'updated_at' => $now]);

    return ['db' => $db, 'org' => $org, 'location' => $location, 'item' => $item];
}

/** @param array{db: Connection, org: string} $world */
function concurrencyCleanup(array $world): void
{
    try {
        // The ledger's own guards (append-only moves, balances only through the ledger) are
        // switched off for this clean-up alone: replica mode skips triggers and constraints.
        $world['db']->statement('set session_replication_role = replica');
        foreach (['stock_moves', 'stock_balances', 'stock_locations', 'item_branch_settings', 'items', 'branches'] as $table) {
            $world['db']->table($table)->where('organization_id', $world['org'])->delete();
        }
        $world['db']->table('organizations')->where('id', $world['org'])->delete();
    } finally {
        $world['db']->statement('set session_replication_role = origin');
        DB::purge('ledger');
    }
}

/**
 * Run the children to completion and return what they printed, in total.
 *
 * @param  list<list<string>>  $commands
 * @return list<string>
 */
function runChildren(array $commands): array
{
    $running = [];
    foreach ($commands as $command) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, base_path('tests/Support/post-stock-moves.php'), ...$command], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
        expect($process)->not->toBeFalse();
        $running[] = [$process, $pipes];
    }

    $printed = [];
    foreach ($running as [$process, $pipes]) {
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0, $err);
        array_push($printed, ...array_filter(array_map('trim', explode("\n", $out))));
    }

    return $printed;
}

/** @return array{on_hand: string, moves: int, summed: string, flagged: int} */
function raceState(array $world): array
{
    $db = $world['db'];
    $balance = $db->table('stock_balances')->where('location_id', $world['location'])->where('item_id', $world['item'])->first();
    $moves = $db->table('stock_moves')->where('location_id', $world['location'])->where('item_id', $world['item']);

    return [
        'on_hand' => (string) $balance->on_hand,
        'moves' => (clone $moves)->count(),
        'summed' => (string) (clone $moves)->sum('quantity'),
        'flagged' => (clone $moves)->where('negative_flag', true)->count(),
    ];
}

it('loses no update when processes issue from one balance at once (allow and flag)', function () {
    $world = concurrencyWorld('allow_and_flag');
    $database = DB::connection()->getDatabaseName();

    try {
        runChildren([['open', $database, $world['location'], $world['item'], '20', '1000']]);

        $printed = runChildren(array_map(fn (int $worker): array => ['issue', $database, $world['location'], $world['item'], '12', (string) $worker], range(1, 4)));
        $state = raceState($world);

        // 48 issues of one against 20 on hand: every one is allowed, and the last 28 flagged.
        expect(array_count_values($printed))->toBe(['ok' => 48])
            ->and($state['moves'])->toBe(49)
            ->and($state['on_hand'])->toBe('-28.000')
            ->and($state['summed'])->toBe('-28.000')
            ->and($state['flagged'])->toBe(28);
    } finally {
        concurrencyCleanup($world);
    }
});

it('never lets processes take more than is there under a blocking branch', function () {
    $world = concurrencyWorld('block');
    $database = DB::connection()->getDatabaseName();

    try {
        runChildren([['open', $database, $world['location'], $world['item'], '30', '1000']]);

        $printed = runChildren(array_map(fn (int $worker): array => ['issue', $database, $world['location'], $world['item'], '12', (string) $worker], range(1, 4)));
        $state = raceState($world);

        // 48 attempts at 30 units: exactly 30 succeed, the rest are refused, and nothing goes below zero.
        expect(array_count_values($printed))->toBe(['ok' => 30, 'blocked' => 18])
            ->and($state['moves'])->toBe(31)
            ->and($state['on_hand'])->toBe('0.000')
            ->and($state['summed'])->toBe('0.000')
            ->and($state['flagged'])->toBe(0);
    } finally {
        concurrencyCleanup($world);
    }
});

it('keeps balances equal to their moves when they are checked at commit', function () {
    $world = concurrencyWorld('allow_and_flag');
    $database = DB::connection()->getDatabaseName();

    try {
        runChildren([['open', $database, $world['location'], $world['item'], '5', '1200']]);
        runChildren([['issue', $database, $world['location'], $world['item'], '3', '9']]);

        // The deferred constraint triggers ran as each child committed; had a balance drifted, that commit would have failed.
        // Draw one out of line now and the same check refuses it, at the next commit point.
        $db = $world['db'];
        $failure = null;
        try {
            $db->transaction(function () use ($db, $world): void {
                $db->select("select set_config('torquelane.stock_ledger', 'on', true)");
                $db->table('stock_balances')->where('location_id', $world['location'])->update(['on_hand' => 99]);
            });
        } catch (Throwable $e) {
            $failure = $e;
        }
        expect($failure)->not->toBeNull()->and($failure?->getMessage())->toContain('but its moves sum to');

        expect(raceState($world)['on_hand'])->toBe('2.000');
    } finally {
        concurrencyCleanup($world);
    }
});

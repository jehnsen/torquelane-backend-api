<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Several processes booking the same slots at once must leave the branch
 * exactly as one process taking the same bookings in turn would: never more
 * heads held in a slot than the pool has, never one detailer on two jobs.
 *
 * The source held `pg_advisory_xact_lock` per date for this. TakeBooking
 * holds the same kind of lock per (branch, date) inside its transaction;
 * these tests prove the lock is there by racing real processes. The rows
 * must be committed for a child to see them, so the test builds its own
 * organization on its own connection and removes it afterwards.
 */

/**
 * @return array{db: Connection, org: string, branch: string, account: string, package: string, detailers: list<string>}
 */
function bookingRaceWorld(int $pool): array
{
    config(['database.connections.booking_race' => config('database.connections.pgsql')]);
    $db = DB::connection('booking_race');
    $ulid = fn (): string => strtolower((string) Str::ulid());
    $now = now();
    $org = $ulid();
    $branch = $ulid();
    $account = $ulid();
    $package = $ulid();
    $stamps = ['created_at' => $now, 'updated_at' => $now];

    $db->table('organizations')->insert(['id' => $org, 'name' => 'Booking Race', 'slug' => 'race-'.$org, 'status' => 'active'] + $stamps);
    $db->table('branches')->insert(['id' => $branch, 'organization_id' => $org, 'name' => 'Race Detailing', 'slug' => 'race-'.$branch, 'is_vat_registered' => true, 'prices_include_vat' => true, 'timezone' => 'Asia/Manila', 'status' => 'active'] + $stamps);
    $db->table('customer_accounts')->insert(['id' => $account, 'organization_id' => $org, 'account_type' => 'individual', 'display_name' => 'Race Customer', 'first_name' => 'Race', 'last_name' => 'Customer', 'status' => 'active'] + $stamps);
    $db->table('detailing_settings')->insert(['id' => $ulid(), 'organization_id' => $org, 'branch_id' => $branch, 'detailer_pool_size' => $pool, 'default_detailers_per_booking' => 1, 'closing_minutes' => 1020, 'size_multipliers' => '{}'] + $stamps);
    $db->table('detailing_packages')->insert(['id' => $package, 'organization_id' => $org, 'branch_id' => $branch, 'name' => 'Race wash', 'duration_text' => '1 hr', 'inclusions' => '[]'] + $stamps);
    $db->table('detailing_package_prices')->insert(['id' => $ulid(), 'organization_id' => $org, 'package_id' => $package, 'size_class' => 'medium', 'price_cents' => 150_000] + $stamps);

    $detailers = [];
    foreach (['Race Ana', 'Race Ben'] as $name) {
        $detailers[] = $id = $ulid();
        $db->table('technicians')->insert(['id' => $id, 'organization_id' => $org, 'branch_id' => $branch, 'name' => $name, 'skill_tags' => '["detailer"]', 'status' => 'active'] + $stamps);
    }

    return ['db' => $db, 'org' => $org, 'branch' => $branch, 'account' => $account, 'package' => $package, 'detailers' => $detailers];
}

/** @param array{db: Connection, org: string} $world */
function bookingRaceCleanup(array $world): void
{
    try {
        // The status log and the audit log are append-only: replica mode skips their triggers for this clean-up alone.
        $world['db']->statement('set session_replication_role = replica');
        foreach ([
            'audit_logs', 'detailing_booking_status_logs', 'detailing_booking_addons', 'detailing_booking_slots', 'detailing_booking_detailers',
            'detailing_bookings', 'detailing_package_prices', 'detailing_packages', 'detailing_settings', 'technicians', 'document_series',
            'customer_accounts', 'branches',
        ] as $table) {
            $world['db']->table($table)->where('organization_id', $world['org'])->delete();
        }
        $world['db']->table('organizations')->where('id', $world['org'])->delete();
    } finally {
        $world['db']->statement('set session_replication_role = origin');
        DB::purge('booking_race');
    }
}

/**
 * Run the children to completion and return what they printed, in total.
 *
 * @param  list<list<string>>  $commands
 * @return list<string>
 */
function raceBookings(array $commands): array
{
    $running = [];
    foreach ($commands as $command) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, base_path('tests/Support/take-bookings.php'), ...$command], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
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

/**
 * How many attempts ended each way, in a fixed order.
 *
 * @param  list<string>  $printed
 * @return array<string, int>
 */
function raceTally(array $printed): array
{
    $tally = array_count_values($printed);
    ksort($tally);

    return $tally;
}

/**
 * @param  array{db: Connection, org: string, branch: string}  $world
 * @return array{bookings: int, references: int, peak: int, slots: array<string, int>}
 */
function bookingRaceState(array $world): array
{
    $db = $world['db'];
    $held = [];
    $rows = $db->table('detailing_booking_slots as s')->join('detailing_bookings as b', 'b.id', '=', 's.booking_id')
        ->where('s.branch_id', $world['branch'])->where('s.released', false)->whereNotIn('b.status', ['cancelled', 'no_show'])
        ->selectRaw('s.date, s.slot, sum(b.detailers_count) as heads')->groupBy('s.date', 's.slot')->orderBy('s.date')->orderBy('s.slot')->get();
    foreach ($rows as $row) {
        $held[substr((string) $row->date, 0, 10).' '.$row->slot] = (int) $row->heads;
    }

    return [
        'bookings' => $db->table('detailing_bookings')->where('organization_id', $world['org'])->count(),
        'references' => $db->table('detailing_bookings')->where('organization_id', $world['org'])->distinct()->count('reference'),
        'peak' => $held === [] ? 0 : max($held),
        'slots' => $held,
    ];
}

it('never books more heads into a slot than the pool has when processes race for it', function () {
    $world = bookingRaceWorld(pool: 3);
    $database = DB::connection()->getDatabaseName();

    try {
        // Six processes, five attempts each, all for 9:00–10:00 on the same day: room for exactly three.
        $printed = raceBookings(array_map(
            fn (int $worker): array => [$database, $world['branch'], $world['account'], $world['package'], '2026-11-02', '540', '5', (string) $worker],
            range(1, 6),
        ));
        $state = bookingRaceState($world);

        expect(raceTally($printed))->toBe(['full' => 27, 'ok' => 3])
            ->and($state['bookings'])->toBe(3)
            ->and($state['references'])->toBe(3)
            ->and($state['slots'])->toBe(['2026-11-02 540' => 3, '2026-11-02 570' => 3])
            ->and($state['peak'])->toBe(3);
    } finally {
        bookingRaceCleanup($world);
    }
});

it('holds the pool across overlapping starts, not just identical ones', function () {
    $world = bookingRaceWorld(pool: 2);
    $database = DB::connection()->getDatabaseName();

    try {
        // 9:00, 9:30 and 10:00 starts of a one-hour job overlap pairwise at 9:30 and 10:00.
        $commands = [];
        foreach ([540, 570, 600] as $start) {
            foreach (range(1, 3) as $worker) {
                $commands[] = [$database, $world['branch'], $world['account'], $world['package'], '2026-11-03', (string) $start, '4', (string) ($start + $worker)];
            }
        }
        $printed = raceTally(raceBookings($commands));
        $state = bookingRaceState($world);

        // Whatever order they landed in, no slot ever holds more than the pool, and every attempt was answered.
        expect(($printed['ok'] ?? 0) + ($printed['full'] ?? 0))->toBe(36)
            ->and($state['bookings'])->toBe($printed['ok'] ?? 0)
            ->and($state['references'])->toBe($state['bookings'])
            ->and($state['peak'])->toBe(2)
            ->and($state['bookings'])->toBeGreaterThanOrEqual(2)->toBeLessThanOrEqual(4);
    } finally {
        bookingRaceCleanup($world);
    }
});

it('never puts one detailer on two jobs when processes name them at once', function () {
    $world = bookingRaceWorld(pool: 5);
    $database = DB::connection()->getDatabaseName();

    try {
        // Five processes each name the same detailer for the same hour: one gets them.
        $printed = raceBookings(array_map(
            fn (int $worker): array => [$database, $world['branch'], $world['account'], $world['package'], '2026-11-04', '540', '3', (string) $worker, $world['detailers'][0]],
            range(1, 5),
        ));
        $named = $world['db']->table('detailing_booking_detailers')->where('organization_id', $world['org'])->where('technician_id', $world['detailers'][0])->count();

        expect(raceTally($printed))->toBe(['detailer_conflict' => 14, 'ok' => 1])
            ->and($named)->toBe(1)
            ->and(bookingRaceState($world)['bookings'])->toBe(1);
    } finally {
        bookingRaceCleanup($world);
    }
});

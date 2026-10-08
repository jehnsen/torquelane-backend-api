<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The fleet half of the demo tenant, from demo-seed.json: the PMS catalogue
 * (in its order), the 32 vehicles with their owners, readings, task state
 * and documents.
 *
 * Readings: the seed stores `odometer`, `odometerReadAt` and `avgDailyKm` on
 * the vehicle; the API derives them from readings. Two readings reproduce
 * them exactly: the current one, and one RATE_SPAN_DAYS earlier at
 * odometer − avgDailyKm × RATE_SPAN_DAYS (MeterRate then derives
 * avgDailyKm × span / span = avgDailyKm). The seed's rates are whole numbers.
 *
 * Documents are metadata only (the seed's paper trail has no files), filed
 * under the vehicle's client. Their work-order links wait for work orders.
 *
 * Bulk inserts with explicit ids and organization: this runs inside the
 * seeder's named system context and writes no audit rows (seed data is not
 * a change anyone made).
 */
final class FleetSeed
{
    public const int RATE_SPAN_DAYS = 30;

    /**
     * @param  array<string, mixed>  $data  demo-seed.json
     * @param  array<string, string>  $ids  source id → ULID, extended in place
     */
    public static function run(string $organizationId, array $data, array &$ids): void
    {
        $now = CarbonImmutable::now('UTC');
        $state = self::map($data['state'] ?? null);

        $tasks = [];
        foreach (self::list($data['serviceTasks'] ?? null) as $position => $task) {
            $task = self::map($task);
            $id = self::ulid();
            $ids['task:'.self::str($task['id'] ?? null)] = $id;
            $tasks[] = [
                'id' => $id,
                'organization_id' => $organizationId,
                'code' => self::str($task['id'] ?? null),
                'name' => self::str($task['name'] ?? null),
                'category' => self::str($task['category'] ?? null),
                'interval_km' => self::int($task['intervalKm'] ?? 0),
                'interval_months' => self::int($task['intervalMonths'] ?? 0),
                'estimated_cost_cents' => Money::of(self::decimal($task['estimatedCost'] ?? 0), 'PHP')->getMinorAmount()->toInt(),
                'estimated_hours' => self::decimal($task['estimatedHours'] ?? 0),
                'critical' => (bool) ($task['critical'] ?? false),
                'is_active' => true,
                'position' => $position,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('service_tasks')->insert($tasks);

        $vehicles = $ownerships = $readings = $states = [];
        foreach (self::list($state['vehicles'] ?? null) as $vehicle) {
            $v = self::map($vehicle);
            $id = self::ulid();
            $source = self::str($v['id'] ?? null);
            $ids[$source] = $id;
            $accountId = $ids[self::str($v['fleetClientId'] ?? null)] ?? throw new RuntimeException("Unknown client for {$source}.");
            $plate = self::str($v['plateNumber'] ?? null);

            $vehicles[] = [
                'id' => $id,
                'organization_id' => $organizationId,
                'customer_account_id' => $accountId,
                'plate_number' => $plate,
                'plate_normalized' => strtoupper((string) preg_replace('/[\s-]/', '', $plate)),
                'make' => $v['make'] ?? null,
                'model' => $v['model'] ?? null,
                'year' => $v['year'] ?? null,
                'vin' => $v['vin'] ?? null,
                'vin_normalized' => is_string($v['vin'] ?? null) ? strtoupper((string) preg_replace('/\s/', '', $v['vin'])) : null,
                'vehicle_class' => $v['vehicleClass'] ?? null,
                'fuel_type' => $v['fuelType'] ?? null,
                'color' => $v['color'] ?? null,
                'status' => self::str($v['status'] ?? null),
                'assigned_to' => $v['assignedTo'] ?? null,
                'department' => $v['department'] ?? null,
                'location' => $v['location'] ?? null,
                'acquired_on' => $v['acquiredOn'] ?? null,
                'registration_expiry' => $v['registrationExpiry'] ?? null,
                'insurance_expiry' => $v['insuranceExpiry'] ?? null,
                'driver_licence_expiry' => $v['driverLicenceExpiry'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $ownerships[] = [
                'id' => self::ulid(),
                'organization_id' => $organizationId,
                'vehicle_id' => $id,
                'customer_account_id' => $accountId,
                'from_date' => self::str($v['acquiredOn'] ?? null),
                'to_date' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $odometer = self::int($v['odometer'] ?? 0);
            $readOn = self::str($v['odometerReadAt'] ?? null);
            $rate = $v['avgDailyKm'] ?? 0;
            if (! is_int($rate)) {
                throw new RuntimeException("{$source}: a fractional daily rate cannot be reproduced from two readings.");
            }
            $reading = fn (int $value, string $on): array => [
                'id' => self::ulid(),
                'organization_id' => $organizationId,
                'asset_type' => 'vehicle',
                'asset_id' => $id,
                'vehicle_id' => $id,
                'meter_kind' => 'km',
                'value' => (string) $value,
                'read_on' => $on,
                'source' => 'import',
                'created_at' => $now,
            ];
            if ($rate > 0) {
                $baseline = $odometer - $rate * self::RATE_SPAN_DAYS;
                if ($baseline < 0) {
                    throw new RuntimeException("{$source}: odometer too low to reproduce its daily rate.");
                }
                $readings[] = $reading($baseline, CarbonImmutable::parse($readOn)->subDays(self::RATE_SPAN_DAYS)->toDateString());
            }
            $readings[] = $reading($odometer, $readOn);

            foreach (self::map($v['taskState'] ?? []) as $taskCode => $taskState) {
                $taskState = self::map($taskState);
                $states[] = [
                    'id' => self::ulid(),
                    'organization_id' => $organizationId,
                    'asset_type' => 'vehicle',
                    'asset_id' => $id,
                    'vehicle_id' => $id,
                    'service_task_id' => $ids['task:'.$taskCode] ?? throw new RuntimeException("Unknown task {$taskCode}."),
                    'meter_kind' => 'km',
                    'last_done_value' => self::decimal($taskState['lastDoneOdometer'] ?? 0),
                    'last_done_on' => self::str($taskState['lastDoneOn'] ?? null),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        DB::table('vehicles')->insert($vehicles);
        DB::table('vehicle_ownerships')->insert($ownerships);
        DB::table('meter_readings')->insert($readings);
        foreach (array_chunk($states, 200) as $chunk) {
            DB::table('maintenance_states')->insert($chunk);
        }

        $documents = [];
        foreach (self::list($state['documents'] ?? null) as $document) {
            $d = self::map($document);
            $id = self::ulid();
            $ids[self::str($d['id'] ?? null)] = $id;
            $documents[] = [
                'id' => $id,
                'organization_id' => $organizationId,
                'customer_account_id' => $ids[self::str($d['fleetClientId'] ?? null)],
                'vehicle_id' => is_string($d['vehicleId'] ?? null) ? $ids[$d['vehicleId']] : null,
                'kind' => self::str($d['kind'] ?? null),
                'name' => self::str($d['name'] ?? null),
                'mime_type' => $d['mimeType'] ?? null,
                'size_bytes' => self::int($d['sizeBytes'] ?? 0),
                'storage_path' => null,
                'expires_on' => $d['expiresOn'] ?? null,
                'reference_number' => $d['referenceNumber'] ?? null,
                'issued_on' => $d['issuedOn'] ?? null,
                'issuing_body' => $d['issuingBody'] ?? null,
                'notes' => ($d['notes'] ?? '') === '' ? null : $d['notes'],
                'uploaded_by' => null,
                'uploaded_by_name' => $d['uploadedBy'] ?? null,
                'uploaded_on' => self::str($d['uploadedOn'] ?? null),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($documents, 100) as $chunk) {
            DB::table('documents')->insert($chunk);
        }
    }

    /**
     * Attaches the seeded documents to their work orders, once the orders
     * exist (WorkOrderSeed runs after this seed).
     *
     * @param  array<string, mixed>  $data  demo-seed.json
     * @param  array<string, string>  $ids
     */
    public static function linkDocuments(array $data, array $ids): void
    {
        $state = self::map($data['state'] ?? null);
        foreach (self::list($state['documents'] ?? null) as $document) {
            $d = self::map($document);
            if (is_string($d['workOrderId'] ?? null)) {
                DB::table('documents')->where('id', $ids[self::str($d['id'] ?? null)])->update(['work_order_id' => $ids[$d['workOrderId']]]);
            }
        }
    }

    private static function int(mixed $value): int
    {
        return is_int($value) ? $value : throw new RuntimeException('Expected a whole number in the demo seed.');
    }

    private static function ulid(): string
    {
        return strtolower((string) Str::ulid());
    }

    private static function decimal(mixed $value): string
    {
        return match (true) {
            is_int($value) => (string) $value,
            is_float($value) => (string) json_encode($value),
            default => throw new RuntimeException('Expected a number in the demo seed.'),
        };
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function map(mixed $value): array
    {
        return is_array($value) ? $value : throw new RuntimeException('Unexpected demo seed shape.');
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    private static function str(mixed $value): string
    {
        return is_string($value) ? $value : throw new RuntimeException('Unexpected demo seed value.');
    }
}

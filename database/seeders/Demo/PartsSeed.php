<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Numbering\DocumentNumberFormat;
use App\Domain\Numbering\DocumentType;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Phase 4's demo data: the provider's vendor list, each fleet client's own
 * spare parts (demo-seed.json `parts`, with the stock ../web seeded) and which
 * service tasks consume them (parts-catalogue.json, from ../web lib/parts.ts),
 * and the clients' purchase orders with their lines and covered due items.
 *
 * Mapping notes:
 *  - purchase orders keep their references; the purchase_order series
 *    continues after the highest, so the next one cannot collide;
 *  - a sent order is stamped sent on its creation date (../web keeps no time);
 *  - names stay names: an order's creator resolves to the demo user of that
 *    name when there is one.
 *
 * Ids: `part:<client>:<part>` and the ../web purchase order and line ids map
 * to the ULIDs created.
 */
final class PartsSeed
{
    /** ../web supabase/migrations/0003_pms_seed.sql, pms_vendors. */
    public const array VENDORS = [
        'Rapide Auto Care — Ortigas',
        'In-house Fleet Bay 2',
        'Ford Global City Service',
        'Isuzu Alabang Service',
        'Bridgestone Tire Center',
        'Toyota Shaw Service Center',
    ];

    /**
     * @param  array<string, mixed>  $data  demo-seed.json
     * @param  array<string, string>  $ids  source id → ULID, extended in place
     */
    public static function run(string $organizationId, array $data, array &$ids): void
    {
        $now = CarbonImmutable::now('UTC');
        $state = self::map($data['state'] ?? null);
        $catalogue = self::catalogue();

        foreach (self::VENDORS as $name) {
            DB::table('vendors')->insert(['id' => self::ulid(), 'organization_id' => $organizationId, 'name' => $name, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }

        $positions = [];
        foreach (self::list($catalogue['partDefinitions'] ?? null) as $index => $definition) {
            $positions[self::str(self::map($definition)['id'] ?? null)] = $index;
        }

        $usages = [];
        foreach (self::list($state['parts'] ?? null) as $part) {
            $p = self::map($part);
            $client = self::str($p['fleetClientId'] ?? null);
            $source = self::str($p['id'] ?? null);
            $id = self::ulid();
            $ids["part:{$client}:{$source}"] = $id;

            DB::table('fleet_parts')->insert([
                'id' => $id,
                'organization_id' => $organizationId,
                'customer_account_id' => $ids[$client],
                'sku' => self::str($p['sku'] ?? null),
                'name' => self::str($p['name'] ?? null),
                'category' => self::str($p['category'] ?? null),
                'unit' => self::str($p['unit'] ?? null),
                'unit_cost_cents' => self::cents($p['unitCost'] ?? null),
                'current_stock' => self::int($p['currentStock'] ?? null),
                'reorder_point' => self::int($p['reorderPoint'] ?? null),
                'preferred_vendor' => self::str($p['preferredVendor'] ?? null),
                'lead_time_days' => self::int($p['leadTimeDays'] ?? null),
                'is_active' => true,
                'position' => $positions[$source] ?? 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach (self::list($catalogue['serviceItemParts'] ?? null) as $position => $link) {
                $l = self::map($link);
                if (($l['partId'] ?? null) !== $source) {
                    continue;
                }
                $task = self::str($l['serviceTaskId'] ?? null);
                $usages[] = [
                    'id' => self::ulid(),
                    'organization_id' => $organizationId,
                    'fleet_part_id' => $id,
                    'service_task_id' => $ids['task:'.$task] ?? throw new RuntimeException("Unknown task {$task}."),
                    'quantity_per_service' => self::int($l['quantityPerService'] ?? null),
                    'position' => $position,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        DB::table('fleet_part_usages')->insert($usages);

        self::purchaseOrders($organizationId, self::list($state['purchaseOrders'] ?? null), $ids, $now);
    }

    /**
     * @param  list<mixed>  $orders
     * @param  array<string, string>  $ids
     */
    private static function purchaseOrders(string $organizationId, array $orders, array &$ids, CarbonImmutable $now): void
    {
        $users = DB::table('users')->where('organization_id', $organizationId)->pluck('id', 'name')->all();
        $highest = [];

        foreach ($orders as $order) {
            $o = self::map($order);
            $client = self::str($o['fleetClientId'] ?? null);
            $reference = self::str($o['reference'] ?? null);
            if (preg_match('/^PO-(\d{4})-(\d+)$/', $reference, $m) === 1) {
                $highest[$m[1]] = max($highest[$m[1]] ?? 0, (int) $m[2]);
            }
            $status = self::str($o['status'] ?? null);
            $createdOn = self::str($o['createdOn'] ?? null);
            $createdBy = self::str($o['createdBy'] ?? null);
            $stamp = CarbonImmutable::parse($createdOn.' 09:00', 'Asia/Manila')->utc();
            $id = self::ulid();
            $ids[self::str($o['id'] ?? null)] = $id;

            $lines = [];
            $total = 0;
            foreach (self::list($o['lines'] ?? null) as $position => $line) {
                $l = self::map($line);
                $lineId = self::ulid();
                $ids[self::str($l['id'] ?? null)] = $lineId;
                $quantity = self::int($l['quantity'] ?? null);
                $unit = self::cents($l['unitCost'] ?? null);
                $total += $quantity * $unit;
                $lines[] = [$lineId, $l, [
                    'id' => $lineId,
                    'organization_id' => $organizationId,
                    'purchase_order_id' => $id,
                    'customer_account_id' => $ids[$client],
                    'fleet_part_id' => $ids["part:{$client}:".self::str($l['partId'] ?? null)] ?? null,
                    'position' => $position,
                    'description' => self::str($l['description'] ?? null),
                    'quantity' => $quantity,
                    'unit_cost_cents' => $unit,
                    'line_total_cents' => $quantity * $unit,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]];
            }

            $sent = in_array($status, ['sent', 'received'], true);
            DB::table('purchase_orders')->insert([
                'id' => $id,
                'organization_id' => $organizationId,
                'customer_account_id' => $ids[$client],
                'reference' => $reference,
                'vendor' => self::str($o['vendor'] ?? null),
                'status' => $status,
                'created_on' => $createdOn,
                'created_by' => $users[$createdBy] ?? null,
                'created_by_name' => $createdBy,
                'notes' => self::str($o['notes'] ?? ''),
                'total_cents' => $total,
                'sent_at' => $sent ? $stamp : null,
                'sent_by_name' => $sent ? $createdBy : null,
                'received_at' => $status === 'received' ? $stamp : null,
                'received_by_name' => $status === 'received' ? $createdBy : null,
                'cancelled_at' => $status === 'cancelled' ? $stamp : null,
                'cancelled_by_name' => $status === 'cancelled' ? $createdBy : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($lines as [$lineId, $l, $row]) {
                DB::table('purchase_order_lines')->insert($row);
                foreach (self::list($l['serviceTaskIds'] ?? []) as $task) {
                    DB::table('purchase_order_line_tasks')->insert(['purchase_order_line_id' => $lineId, 'service_task_id' => $ids['task:'.self::str($task)], 'organization_id' => $organizationId]);
                }
                foreach (self::list($l['vehicleIds'] ?? []) as $vehicle) {
                    DB::table('purchase_order_line_vehicles')->insert(['purchase_order_line_id' => $lineId, 'vehicle_id' => $ids[self::str($vehicle)], 'organization_id' => $organizationId]);
                }
            }

            $events = [['draft', $stamp]];
            if ($status !== 'draft') {
                $events[] = [$status, $stamp];
            }
            foreach ($events as [$eventStatus, $at]) {
                DB::table('purchase_order_events')->insert(['id' => self::ulid(), 'organization_id' => $organizationId, 'purchase_order_id' => $id, 'status' => $eventStatus, 'at' => $at, 'actor_id' => $users[$createdBy] ?? null, 'actor_name' => $createdBy, 'note' => null, 'created_at' => $now]);
            }
        }

        foreach ($highest as $year => $number) {
            DB::table('document_series')->insert([
                'id' => self::ulid(),
                'organization_id' => $organizationId,
                'branch_id' => null,
                'doc_type' => DocumentType::PurchaseOrder->value,
                'period_key' => (string) $year,
                'prefix' => DocumentType::PurchaseOrder->defaultPrefix(),
                'next_number' => $number + 1,
                'padding' => DocumentNumberFormat::DEFAULT_PADDING,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function catalogue(): array
    {
        $json = file_get_contents(dirname(__DIR__).'/data/parts-catalogue.json');
        $decoded = json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);

        return self::map($decoded);
    }

    private static function cents(mixed $value): int
    {
        if (is_int($value)) {
            return Money::of($value, 'PHP')->getMinorAmount()->toInt();
        }
        if (is_float($value)) {
            return Money::of((string) json_encode($value), 'PHP')->getMinorAmount()->toInt();
        }

        throw new RuntimeException('Expected an amount in the demo seed.');
    }

    private static function int(mixed $value): int
    {
        return is_int($value) ? $value : throw new RuntimeException('Expected an integer in the demo seed.');
    }

    private static function ulid(): string
    {
        return strtolower((string) Str::ulid());
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function map(mixed $value): array
    {
        return is_array($value) ? $value : throw new RuntimeException('Expected an object in the demo seed.');
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
        return is_string($value) ? $value : throw new RuntimeException('Expected a string in the demo seed.');
    }
}

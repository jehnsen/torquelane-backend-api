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
 * ../web's 484 demo work orders, their lines, tasks, parts, status history
 * and approval log, plus the organization's approval settings — all booked
 * into the repair branch.
 *
 * Mapping notes:
 *  - a draft is seeded UNNUMBERED (reference ''): ../web's seed numbers
 *    drafts, but here a draft never burns a number;
 *  - the work_order series continues after the highest seeded number, so
 *    the next order sent for approval cannot collide with a seeded one;
 *  - names stay names: technicians resolve to the branch's technician rows,
 *    approvers and event actors keep their recorded names (the seed has no
 *    user ids for them), and a collection is credited to the demo user of
 *    that name.
 */
final class WorkOrderSeed
{
    /**
     * @param  array<string, mixed>  $data  demo-seed.json
     * @param  array<string, string>  $ids  source id → ULID, extended in place
     */
    public static function run(string $organizationId, string $branchId, array $data, array &$ids): void
    {
        $now = CarbonImmutable::now('UTC');
        $state = self::map($data['state'] ?? null);

        self::settings($organizationId, self::map($state['approvalSettings'] ?? null), $now);

        $technicians = DB::table('technicians')->where('branch_id', $branchId)->pluck('id', 'name')->all();
        $users = DB::table('users')->where('organization_id', $organizationId)->pluck('id', 'name')->all();
        $accountOf = [];
        foreach (self::list($state['vehicles'] ?? null) as $vehicle) {
            $v = self::map($vehicle);
            $accountOf[self::str($v['id'] ?? null)] = $ids[self::str($v['fleetClientId'] ?? null)];
        }

        $orders = $lines = $tasks = $parts = $events = $log = [];
        $highest = [];
        foreach (self::list($state['workOrders'] ?? null) as $order) {
            $o = self::map($order);
            $source = self::str($o['id'] ?? null);
            $id = self::ulid();
            $ids[$source] = $id;
            $vehicleSource = self::str($o['vehicleId'] ?? null);
            $status = self::str($o['status'] ?? null);
            $reference = $status === 'draft' ? '' : self::str($o['reference'] ?? null);
            if ($reference !== '' && preg_match('/^WO-(\d{4})-(\d+)$/', $reference, $m) === 1) {
                $highest[$m[1]] = max($highest[$m[1]] ?? 0, (int) $m[2]);
            }
            $technician = self::str($o['technician'] ?? '');
            $collectedBy = is_string($o['collectedBy'] ?? null) ? ($users[$o['collectedBy']] ?? throw new RuntimeException("No demo user named {$o['collectedBy']}.")) : null;

            $orders[] = [
                'id' => $id,
                'organization_id' => $organizationId,
                'branch_id' => $branchId,
                'assigned_branch_id' => is_string($o['assignedProviderId'] ?? null) ? $branchId : null,
                'customer_account_id' => $accountOf[$vehicleSource],
                'vehicle_id' => $ids[$vehicleSource],
                'reference' => $reference,
                'title' => self::str($o['title'] ?? null),
                'type' => self::str($o['type'] ?? null),
                'status' => $status,
                'priority' => self::str($o['priority'] ?? null),
                'opened_on' => self::str($o['openedOn'] ?? null),
                'scheduled_for' => ($o['scheduledFor'] ?? '') === '' ? null : $o['scheduledFor'],
                'scheduled_time' => $o['scheduledTime'] ?? null,
                'bay_id' => is_string($o['bayId'] ?? null) ? $ids['bay:'.$o['bayId']] : null,
                'technician_id' => $technicians[$technician] ?? null,
                'technician_name' => $technician === '' ? null : $technician,
                'vendor' => self::str($o['vendor'] ?? ''),
                'odometer_at_intake' => null,
                'odometer_at_service' => self::decimal($o['odometerAtService'] ?? 0),
                'labor_cost_cents' => self::cents($o['laborCost'] ?? 0),
                'parts_cost_cents' => self::cents($o['partsCost'] ?? 0),
                'findings' => self::str($o['findings'] ?? ''),
                'notes' => self::str($o['notes'] ?? ''),
                'pending_approval_entered_at' => $o['pendingApprovalEnteredAt'] ?? null,
                'approval_wait_hours' => isset($o['approvalWaitHours']) ? self::decimal($o['approvalWaitHours']) : null,
                'completed_on' => $o['completedOn'] ?? null,
                'collected_at' => $o['collectedAt'] ?? null,
                'collected_by' => $collectedBy,
                // Before invoicing (Phase 7), collecting a job was also handing the vehicle back.
                'released_at' => $o['collectedAt'] ?? null,
                'released_by' => $collectedBy,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $lineIds = [];
            foreach (self::list($o['lines'] ?? []) as $position => $line) {
                $l = self::map($line);
                $lineId = self::ulid();
                $lineIds[self::str($l['id'] ?? null)] = $lineId;
                $lines[] = [
                    'id' => $lineId,
                    'organization_id' => $organizationId,
                    'work_order_id' => $id,
                    'position' => $position,
                    'service_task_id' => is_string($l['serviceTaskId'] ?? null) ? $ids['task:'.$l['serviceTaskId']] : null,
                    'description' => self::str($l['description'] ?? null),
                    'category' => self::str($l['category'] ?? 'other'),
                    'quantity' => self::decimal($l['quantity'] ?? 1),
                    'unit_part_rate_cents' => self::cents($l['unitPartRate'] ?? 0),
                    'part_cost_cents' => self::cents($l['partCost'] ?? 0),
                    'labour_hours' => self::decimal($l['labourHours'] ?? 0),
                    'labour_rate_cents' => self::cents($l['labourRate'] ?? 0),
                    'labour_cost_cents' => self::cents($l['labourCost'] ?? 0),
                    'urgency' => self::str($l['urgency'] ?? null),
                    'parts_source' => self::str($l['partsSource'] ?? null),
                    'approval_status' => self::str($l['approvalStatus'] ?? null),
                    'approved_by' => null,
                    'approved_by_name' => $l['approvedBy'] ?? null,
                    'approved_at' => $l['approvedAt'] ?? null,
                    'decline_reason' => $l['declineReason'] ?? null,
                    'photos' => json_encode(self::list($l['photoUrls'] ?? []), JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_unique(array_map(self::str(...), self::list($o['taskIds'] ?? []))) as $taskCode) {
                $tasks[] = ['id' => self::ulid(), 'organization_id' => $organizationId, 'work_order_id' => $id, 'service_task_id' => $ids['task:'.$taskCode], 'created_at' => $now];
            }
            foreach (self::list($o['parts'] ?? []) as $position => $part) {
                $p = self::map($part);
                $parts[] = [
                    'id' => self::ulid(),
                    'organization_id' => $organizationId,
                    'work_order_id' => $id,
                    'position' => $position,
                    'part_number' => $p['partNumber'] ?? null,
                    'name' => self::str($p['name'] ?? null),
                    'quantity' => self::decimal($p['quantity'] ?? 0),
                    'unit_cost_cents' => self::cents($p['unitCost'] ?? 0),
                    'created_at' => $now,
                ];
            }
            foreach (self::list($o['history'] ?? []) as $event) {
                $e = self::map($event);
                $events[] = [
                    'id' => self::ulid(),
                    'organization_id' => $organizationId,
                    'work_order_id' => $id,
                    'status' => self::str($e['status'] ?? null),
                    'at' => self::str($e['at'] ?? null),
                    'actor_id' => null,
                    'actor_name' => self::str($e['actor'] ?? null),
                    'created_at' => $now,
                ];
            }
            foreach (self::list($o['approvalLog'] ?? []) as $entry) {
                $a = self::map($entry);
                $log[] = [
                    'id' => self::ulid(),
                    'organization_id' => $organizationId,
                    'work_order_id' => $id,
                    'line_id' => is_string($a['lineId'] ?? null) ? $lineIds[$a['lineId']] : null,
                    'action' => self::str($a['action'] ?? null),
                    'actor_id' => null,
                    'actor_name' => self::str($a['actorName'] ?? null),
                    'at' => self::str($a['at'] ?? null),
                    'note' => $a['note'] ?? null,
                    'amount_at_time_cents' => self::cents($a['amountAtTime'] ?? 0),
                    'created_at' => $now,
                ];
            }
        }

        foreach (['work_orders' => $orders, 'work_order_lines' => $lines, 'work_order_tasks' => $tasks, 'work_order_parts' => $parts, 'work_order_events' => $events, 'approval_log' => $log] as $table => $rows) {
            foreach (array_chunk($rows, 100) as $chunk) {
                DB::table($table)->insert($chunk);
            }
        }

        foreach ($highest as $year => $number) {
            DB::table('document_series')->insert([
                'id' => self::ulid(),
                'organization_id' => $organizationId,
                'branch_id' => null,
                'doc_type' => DocumentType::WorkOrder->value,
                'period_key' => (string) $year,
                'prefix' => DocumentType::WorkOrder->defaultPrefix(),
                'next_number' => $number + 1,
                'padding' => DocumentNumberFormat::DEFAULT_PADDING,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * @param  array<array-key, mixed>  $s
     */
    private static function settings(string $organizationId, array $s, CarbonImmutable $now): void
    {
        DB::table('approval_settings')->insert([
            'id' => self::ulid(),
            'organization_id' => $organizationId,
            'branch_id' => null,
            'auto_approve_under_cents' => self::cents($s['autoApproveUnder'] ?? null),
            'ops_approval_under_cents' => self::cents($s['opsApprovalUnder'] ?? null),
            'sla_hours' => $s['slaHours'] ?? null,
            'variance_threshold_pct' => self::decimal($s['varianceThresholdPct'] ?? null),
            'default_parts_source' => self::str($s['defaultPartsSource'] ?? null),
            'monthly_budget_cents' => self::cents($s['monthlyBudget'] ?? null),
            'vat_rate_pct' => self::decimal($s['vatRatePct'] ?? null),
            'misc_fee_flat_cents' => self::cents($s['miscFeeFlat'] ?? null),
            'default_labour_rate_cents' => self::cents($s['defaultLabourRate'] ?? null),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private static function cents(mixed $value): int
    {
        return Money::of(self::decimal($value), 'PHP')->getMinorAmount()->toInt();
    }

    private static function decimal(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return (string) json_encode($value);
        }

        throw new RuntimeException('Expected a number in the demo seed.');
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

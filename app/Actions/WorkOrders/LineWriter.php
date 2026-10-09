<?php

declare(strict_types=1);

namespace App\Actions\WorkOrders;

use App\Domain\Approvals\ApprovalSettings;
use App\Domain\Approvals\LineApprovalStatus;
use App\Domain\Billing\BillableLine;
use App\Domain\Billing\Billing;
use App\Domain\WorkOrders\LineUrgency;
use App\Domain\WorkOrders\PartsSource;
use App\Exceptions\ConflictException;
use App\Models\ApprovalLogEntry;
use App\Models\Item;
use App\Models\ItemBranchSetting;
use App\Models\ServiceTask;
use App\Models\WorkOrder;
use App\Models\WorkOrderLine;
use App\Models\WorkOrderTask;
use Illuminate\Validation\ValidationException;

/**
 * Writes a draft's lines from the caller's inputs. The client sends
 * quantities and rates only: every cost is recomputed here through
 * Billing::recalc, and any total the client sent is never read.
 */
final class LineWriter
{
    /**
     * Replaces the order's lines with $inputs: an input with an `id` updates
     * that line, one without creates a line, and a line left out is removed —
     * unless it has approval history, which keeps it.
     *
     * @param  list<array<string, mixed>>  $inputs
     */
    public function replace(WorkOrder $order, array $inputs, ApprovalSettings $settings): void
    {
        $existing = $order->lines->keyBy('id');
        $kept = [];
        $tasks = $this->tasks($inputs);

        foreach ($inputs as $position => $input) {
            $id = isset($input['id']) && is_string($input['id']) ? $input['id'] : null;
            if ($id !== null && ! $existing->has($id)) {
                throw ValidationException::withMessages(["lines.{$position}.id" => 'That line is not on this work order.']);
            }
            $found = $id === null ? null : $existing->get($id);
            $line = $found instanceof WorkOrderLine ? $found : new WorkOrderLine;
            $this->fill($order, $line, $input, $position, $settings, $tasks);
            $line->save();
            $kept[] = $line->id;
        }

        foreach ($existing as $id => $line) {
            if (in_array($id, $kept, true)) {
                continue;
            }
            if (ApprovalLogEntry::query()->where('line_id', $id)->exists()) {
                throw new ConflictException('A line with approval history stays on the order; re-price it instead of removing it.');
            }
            $line->delete();
        }

        $this->refreshEstimate($order);
    }

    /**
     * The order's estimate figures follow its lines (the aggregate a line-less
     * report reads); itemised lines and parts remain the source of truth.
     */
    public function refreshEstimate(WorkOrder $order): void
    {
        $lines = array_values($order->lines()->get()->map(fn (WorkOrderLine $line): BillableLine => $line->billable())->all());
        $totals = Billing::totals($lines, '0', 0);
        $order->forceFill([
            'labor_cost_cents' => $totals->labourTotalCents,
            'parts_cost_cents' => $totals->partsTotalCents,
        ])->save();
        $order->unsetRelation('lines');
    }

    /**
     * @param  list<string>  $taskIds
     */
    public function replaceTasks(WorkOrder $order, array $taskIds): void
    {
        $taskIds = array_values(array_unique($taskIds));
        $known = array_map(fn (ServiceTask $task): string => $task->id, ServiceTask::query()->whereIn('id', $taskIds)->get(['id'])->all());
        $unknown = array_diff($taskIds, $known);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['task_ids' => 'Unknown service task(s).']);
        }

        WorkOrderTask::query()->where('work_order_id', $order->id)->whereNotIn('service_task_id', $taskIds)->delete();
        $have = array_map(fn (WorkOrderTask $task): string => $task->service_task_id, WorkOrderTask::query()->where('work_order_id', $order->id)->get()->all());
        foreach (array_diff($taskIds, $have) as $taskId) {
            $task = new WorkOrderTask;
            $task->forceFill(['work_order_id' => $order->id, 'service_task_id' => $taskId])->save();
        }
        $order->unsetRelation('tasks');
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, ServiceTask>  $tasks
     */
    private function fill(WorkOrder $order, WorkOrderLine $line, array $input, int $position, ApprovalSettings $settings, array $tasks): void
    {
        $taskId = isset($input['service_task_id']) && is_string($input['service_task_id']) ? $input['service_task_id'] : null;
        $task = $taskId === null ? null : $tasks[$taskId];

        $source = is_string($input['parts_source'] ?? null) ? PartsSource::from($input['parts_source']) : $settings->defaultPartsSource;
        $item = $this->itemFor($order, $source, $input);

        // What the customer is charged for the part follows where it comes from:
        // their own part is free; a shelf part defaults to the branch's price.
        $unitPartRate = match (true) {
            $source === PartsSource::CustomerSupplied => 0,
            $item !== null && ! isset($input['unit_part_rate_cents']) => $this->priceAt($item, $order),
            default => self::int($input['unit_part_rate_cents'] ?? 0),
        };

        $priced = Billing::recalc(new BillableLine(
            self::decimal($input['quantity'] ?? '1'),
            $unitPartRate,
            self::decimal($input['labour_hours'] ?? '0'),
            self::int($input['labour_rate_cents'] ?? $settings->defaultLabourRateCents),
            0,
            0,
        ));

        $line->forceFill([
            'work_order_id' => $order->id,
            'position' => $position,
            'service_task_id' => $taskId,
            'description' => is_string($input['description'] ?? null) ? $input['description'] : ($task->name ?? ''),
            'category' => is_string($input['category'] ?? null) ? $input['category'] : ($task->category ?? 'other'),
            'quantity' => $priced->quantity,
            'unit_part_rate_cents' => $priced->unitPartRateCents,
            'part_cost_cents' => $priced->partCostCents,
            'labour_hours' => $priced->labourHours,
            'labour_rate_cents' => $priced->labourRateCents,
            'labour_cost_cents' => $priced->labourCostCents,
            'urgency' => LineUrgency::from(is_string($input['urgency'] ?? null) ? $input['urgency'] : LineUrgency::Recommended->value),
            'parts_source' => $source,
            'item_id' => $item?->id,
            'photos' => array_values(array_filter(is_array($input['photos'] ?? null) ? $input['photos'] : [], 'is_string')),
        ]);

        // A draft's line is a fresh quote: any earlier answer no longer applies.
        if (! $line->exists || $line->approval_status !== LineApprovalStatus::Pending) {
            $line->forceFill([
                'approval_status' => LineApprovalStatus::Pending,
                'approved_by' => null,
                'approved_by_name' => null,
                'approved_at' => null,
                'decline_reason' => null,
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $inputs
     * @return array<string, ServiceTask>
     */
    private function tasks(array $inputs): array
    {
        $ids = [];
        foreach ($inputs as $input) {
            if (isset($input['service_task_id']) && is_string($input['service_task_id'])) {
                $ids[] = $input['service_task_id'];
            }
        }
        $tasks = [];
        foreach (ServiceTask::query()->whereIn('id', array_unique($ids))->get() as $task) {
            $tasks[$task->id] = $task;
        }
        if (count($tasks) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['lines' => 'A line names an unknown service task.']);
        }

        return $tasks;
    }

    /**
     * The inventory item a shop-stock line issues: required for that source,
     * and refused for any other.
     *
     * @param  array<string, mixed>  $input
     */
    private function itemFor(WorkOrder $order, PartsSource $source, array $input): ?Item
    {
        $itemId = isset($input['item_id']) && is_string($input['item_id']) ? $input['item_id'] : null;
        if (! $source->movesStock()) {
            if ($itemId !== null) {
                throw ValidationException::withMessages(['lines' => 'Only a line whose parts come from shop stock names an inventory item.']);
            }

            return null;
        }
        if ($itemId === null) {
            throw ValidationException::withMessages(['lines' => 'A line whose parts come from shop stock needs the inventory item.']);
        }
        $item = Item::query()->find($itemId);
        if (! $item instanceof Item || ! $item->is_stocked) {
            throw ValidationException::withMessages(['lines' => 'That is not a stocked inventory item.']);
        }
        if (! $item->is_active) {
            throw ValidationException::withMessages(['lines' => "{$item->name} is inactive."]);
        }

        return $item;
    }

    /** The branch's price for an item: its own override, else the item's price. */
    private function priceAt(Item $item, WorkOrder $order): int
    {
        $branchId = $order->branch_id ?? $order->assigned_branch_id;
        $override = $branchId === null ? null : ItemBranchSetting::query()->where('item_id', $item->id)->where('branch_id', $branchId)->value('price_override_cents');

        return is_int($override) ? $override : $item->default_price_cents;
    }

    private static function decimal(mixed $value): string
    {
        return is_int($value) || is_float($value) || is_string($value) ? (string) $value : '0';
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\WorkOrders;

use App\Domain\WorkOrders\WorkOrderStatus;
use App\Exceptions\InvalidTransitionException;
use App\Models\WorkOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * updateDraft and recordLines. Only a draft is edited; a declined quote is
 * reopened as a draft by updateDraft (it keeps its number). An approved
 * amount is the historical price and no action edits it.
 */
final class EditWorkOrder
{
    public const array DRAFT_FIELDS = ['title', 'type', 'priority', 'notes', 'vendor', 'scheduled_for', 'scheduled_time', 'odometer_at_intake'];

    public function __construct(
        private readonly WorkOrderJournal $journal,
        private readonly LineWriter $lines,
        private readonly ApprovalSettingsResolver $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated (UpdateWorkOrderRequest)
     */
    public function updateDraft(WorkOrder $order, array $data): WorkOrder
    {
        return DB::transaction(function () use ($order, $data): WorkOrder {
            $locked = $this->journal->lock($order);
            $before = WorkOrderJournal::snapshot($locked);

            if ($locked->status === WorkOrderStatus::Declined) {
                $this->journal->guard($locked, WorkOrderStatus::Draft);
                $locked->forceFill(['status' => WorkOrderStatus::Draft])->save();
                $this->journal->event($locked, WorkOrderStatus::Draft, CarbonImmutable::now());
            } elseif ($locked->status !== WorkOrderStatus::Draft) {
                throw new InvalidTransitionException('Only a draft (or a declined quote, which reopens as one) can be edited.');
            }

            $locked->forceFill(array_intersect_key($data, array_flip(self::DRAFT_FIELDS)))->save();
            if (array_key_exists('task_ids', $data) && is_array($data['task_ids'])) {
                $this->lines->replaceTasks($locked, array_values(array_filter($data['task_ids'], 'is_string')));
            }
            $this->journal->audit($locked, 'draft_updated', $before);

            return $locked;
        });
    }

    /**
     * The server prices every line; the client's figures are never read.
     *
     * @param  list<array<string, mixed>>  $lines  validated
     */
    public function recordLines(WorkOrder $order, array $lines): WorkOrder
    {
        return DB::transaction(function () use ($order, $lines): WorkOrder {
            $locked = $this->journal->lock($order);
            if ($locked->status !== WorkOrderStatus::Draft) {
                throw new InvalidTransitionException('Lines are edited only on a draft. Reopen a declined quote first.');
            }
            $before = WorkOrderJournal::snapshot($locked);

            $this->lines->replace($locked, $lines, $this->settings->forOrder($locked));
            $this->journal->audit($locked, 'lines_recorded', $before);

            return $locked;
        });
    }
}

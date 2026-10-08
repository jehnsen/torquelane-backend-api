<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Fleet\PmsItem;
use App\Domain\Shared\WebFormat;
use App\Models\ServiceTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One task evaluated against one vehicle (engine output), with its label.
 *
 * @property PmsItem $resource
 */
final class PmsItemResource extends JsonResource
{
    /**
     * @param  array<string, ServiceTask>  $tasks  catalogue rows by id
     */
    public function __construct(PmsItem $resource, private readonly array $tasks)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = $this->resource;
        $task = $this->tasks[$item->task->id] ?? null;

        return [
            'service_task_id' => $item->task->id,
            'task' => [
                'id' => $item->task->id,
                'code' => $task?->code,
                'name' => $item->task->name,
                'category' => $task?->category,
                'critical' => $item->task->critical,
                'interval_km' => $item->task->intervalKm,
                'interval_months' => $item->task->intervalMonths,
            ],
            // ok | due_soon | overdue
            'status' => $item->status,
            // Negative once the interval has been passed.
            'km_remaining' => $item->kmRemaining,
            'days_remaining' => $item->daysRemaining,
            'due_label' => WebFormat::dayDelta($item->daysRemaining),
            // 0–1+; above 1 means overdue.
            'progress' => $item->progress,
            'due_odometer' => $item->dueOdometer,
            'due_date' => $item->dueDate,
            // distance | time: whichever limit arrives first.
            'governed_by' => $item->governedBy,
            'last_done_on' => $item->lastDoneOn,
            'last_done_odometer' => $item->lastDoneOdometer,
        ];
    }
}

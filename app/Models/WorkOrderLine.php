<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\DecimalCast;
use App\Domain\Approvals\LineApprovalStatus;
use App\Domain\Billing\BillableLine;
use App\Domain\WorkOrders\LineUrgency;
use App\Domain\WorkOrders\PartsSource;
use App\Tenancy\BelongsToOrganization;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * `part_cost_cents` / `labour_cost_cents` are stored, written only through
 * Billing::recalc (a CHECK holds them to round(qty × rate)); an approved
 * line's price never changes (trigger).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $work_order_id
 * @property int $position
 * @property string|null $service_task_id
 * @property string $description
 * @property string $category
 * @property BigDecimal $quantity
 * @property int $unit_part_rate_cents
 * @property int $part_cost_cents
 * @property BigDecimal $labour_hours
 * @property int $labour_rate_cents
 * @property int $labour_cost_cents
 * @property LineUrgency $urgency
 * @property PartsSource $parts_source
 * @property LineApprovalStatus $approval_status
 * @property string|null $approved_by
 * @property string|null $approved_by_name
 * @property CarbonImmutable|null $approved_at
 * @property string|null $decline_reason
 * @property list<string> $photos
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class WorkOrderLine extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    protected $attributes = ['photos' => '[]', 'approval_status' => 'pending', 'category' => 'other'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'quantity' => DecimalCast::class,
            'unit_part_rate_cents' => 'integer',
            'part_cost_cents' => 'integer',
            'labour_hours' => DecimalCast::class,
            'labour_rate_cents' => 'integer',
            'labour_cost_cents' => 'integer',
            'urgency' => LineUrgency::class,
            'parts_source' => PartsSource::class,
            'approval_status' => LineApprovalStatus::class,
            'approved_at' => 'immutable_datetime',
            'photos' => 'array',
        ];
    }

    public function billable(): BillableLine
    {
        return new BillableLine(
            (string) $this->quantity->strippedOfTrailingZeros(),
            $this->unit_part_rate_cents,
            (string) $this->labour_hours->strippedOfTrailingZeros(),
            $this->labour_rate_cents,
            $this->part_cost_cents,
            $this->labour_cost_cents,
            $this->approval_status,
            $this->parts_source,
        );
    }

    public function cost(): int
    {
        return $this->part_cost_cents + $this->labour_cost_cents;
    }
}

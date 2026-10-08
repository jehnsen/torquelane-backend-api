<?php

declare(strict_types=1);

namespace App\Domain\WorkOrders;

use App\Domain\Billing\BillableLine;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;

/**
 * What the shop-floor and reporting rules read from a work order: a plain
 * snapshot, loaded by the caller (already scoped), money in centavos.
 */
final readonly class WorkOrderFacts
{
    /**
     * @param  list<string>  $taskIds
     * @param  list<PartFacts>  $parts  parts a technician recorded at close-out
     * @param  list<BillableLine>  $lines
     * @param  list<StatusEvent>  $history  oldest first
     */
    public function __construct(
        public string $id,
        public WorkOrderStatus $status,
        public string $vehicleId,
        /** Stamped at creation: the order stays with the account that owned the vehicle then. */
        public ?string $customerAccountId,
        /** Y-m-d, or null when not booked. */
        public ?string $scheduledFor,
        public ?string $bayId,
        public array $taskIds,
        public int $laborCostCents,
        /** The estimate's aggregate parts figure; itemised parts win once recorded. */
        public int $partsCostCents,
        public array $parts,
        public array $lines,
        public array $history,
        /** Y-m-d */
        public ?string $completedOn,
        public ?DateTimeImmutable $collectedAt,
        /** Whoever is assigned: a technician id here, a name in ../web. */
        public string $technician,
        public ?DateTimeImmutable $pendingApprovalEnteredAt,
        public float|int|null $approvalWaitHours,
    ) {}

    /** ../web pms.ts resolvePartsCost: itemised parts once recorded, else the estimate. Rounded once. */
    public function partsCents(): int
    {
        if ($this->parts === []) {
            return $this->partsCostCents;
        }

        $total = BigDecimal::zero();
        foreach ($this->parts as $part) {
            $total = $total->plus(BigDecimal::of($part->quantity)->multipliedBy($part->unitCostCents));
        }

        return $total->toScale(0, RoundingMode::HalfUp)->toInt();
    }

    /** ../web pms.ts workOrderCost: labour plus resolved parts. */
    public function costCents(): int
    {
        return $this->laborCostCents + $this->partsCents();
    }
}

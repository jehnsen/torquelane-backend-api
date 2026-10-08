<?php

declare(strict_types=1);

namespace App\Domain\Maintenance;

use DateTimeImmutable;

final readonly class IntervalOutcome
{
    public const string TIME = 'time';

    /**
     * @param  array<string, MeterProjection>  $meters  by MeterKind value
     */
    public function __construct(
        public array $meters,
        public ?DateTimeImmutable $calendarDueOn,
        /** The governing limit: a MeterKind value, or `time`; null when nothing ever falls due. */
        public ?string $governedBy,
        public ?DateTimeImmutable $projectedDue,
        public IntervalBand $status,
        /** Signed days to the governing limit; null when nothing ever falls due. */
        public ?int $daysRemaining,
        public float|int $progress,
    ) {}

    public function meter(MeterKind $kind): ?MeterProjection
    {
        return $this->meters[$kind->value] ?? null;
    }
}

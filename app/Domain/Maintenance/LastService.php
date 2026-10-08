<?php

declare(strict_types=1);

namespace App\Domain\Maintenance;

use DateTimeImmutable;

/** When an item was last done, and each meter's value at that moment. */
final readonly class LastService
{
    /**
     * @param  array<string, float|int>  $meterValues  MeterKind value → value at completion
     */
    public function __construct(
        public DateTimeImmutable $doneOn,
        public array $meterValues,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Domain\Parts;

/** One projected due item driving a part's demand. */
final readonly class DemandContributor
{
    public function __construct(
        public string $vehicleId,
        public string $taskId,
        /** Y-m-d */
        public string $dueDate,
    ) {}
}

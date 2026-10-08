<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

/** When a task was last done on a vehicle (maintenance_states row). */
final readonly class TaskState
{
    public function __construct(
        public float|int $lastDoneOdometer,
        /** Y-m-d */
        public string $lastDoneOn,
    ) {}
}

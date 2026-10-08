<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

/** What closing a work order tells the engine (the subset applyCompletion reads). */
final readonly class CompletedService
{
    /**
     * @param  list<string>  $taskIds
     */
    public function __construct(
        public array $taskIds,
        public float|int $odometerAtService,
        /** Y-m-d; null means "today". */
        public ?string $completedOn,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Domain\Shop;

/**
 * The branch facts the floor maths reads: its bays, the catalogue's hour
 * estimates and names, and the labour rate that turns a labour figure into
 * hours when the catalogue has no estimate. (../web reads these from static
 * modules; here they come from the branch's own records.)
 */
final readonly class ShopContext
{
    /**
     * @param  list<BayFacts>  $bays
     * @param  array<string, float|int>  $taskHours  service task id → estimated hours
     * @param  array<string, string>  $taskNames  service task id → name
     */
    public function __construct(
        public array $bays,
        public array $taskHours,
        public array $taskNames,
        public int $labourRateCents,
    ) {}

    public function capacityHours(): float|int
    {
        return array_sum(array_map(fn (BayFacts $bay): float|int => $bay->capacityHoursPerDay, $this->bays));
    }
}

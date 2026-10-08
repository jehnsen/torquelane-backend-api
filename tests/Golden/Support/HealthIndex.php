<?php

declare(strict_types=1);

namespace Tests\Golden\Support;

use App\Domain\Fleet\PmsItem;
use App\Domain\Fleet\VehicleFacts;
use App\Domain\Fleet\VehicleHealth;
use SplObjectStorage;

/**
 * Domain health built from a fixture, with the fixture records each object
 * came from.
 *
 * @internal
 */
final class HealthIndex
{
    /** @var list<VehicleHealth> */
    public array $health = [];

    /** @var SplObjectStorage<VehicleFacts, mixed> */
    public SplObjectStorage $vehicles;

    /** @var SplObjectStorage<PmsItem, mixed> */
    public SplObjectStorage $items;

    /** @var array<string, int> */
    public array $taskCostCents = [];

    public function __construct()
    {
        $this->vehicles = new SplObjectStorage;
        $this->items = new SplObjectStorage;
    }
}

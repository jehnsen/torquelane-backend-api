<?php

declare(strict_types=1);

namespace App\Domain\Maintenance;

/** What a meter counts. Vehicles have `km`; equipment (Phase 10) the others. */
enum MeterKind: string
{
    case Km = 'km';
    case Hours = 'hours';
    case Cycles = 'cycles';
    case Cups = 'cups';
}

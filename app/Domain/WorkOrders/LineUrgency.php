<?php

declare(strict_types=1);

namespace App\Domain\WorkOrders;

/** A safety-critical line cannot be declined without a reason on record. */
enum LineUrgency: string
{
    case SafetyCritical = 'safety_critical';
    case Recommended = 'recommended';
    case Optional = 'optional';
}

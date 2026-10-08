<?php

declare(strict_types=1);

namespace App\Domain\Maintenance;

enum IntervalBand: string
{
    case OnSchedule = 'on_schedule';
    case DueSoon = 'due_soon';
    case Overdue = 'overdue';
}

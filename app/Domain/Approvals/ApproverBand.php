<?php

declare(strict_types=1);

namespace App\Domain\Approvals;

/** Who must approve a pending amount (../web approvals.ts ApproverBand). */
enum ApproverBand: string
{
    case Auto = 'auto';
    case Operations = 'operations';
    case FleetManager = 'fleet_manager';
}

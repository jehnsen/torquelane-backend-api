<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A catalogue task a work order discharges: closing the order resets this
 * task's interval on the vehicle.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $work_order_id
 * @property string $service_task_id
 * @property CarbonImmutable $created_at
 */
final class WorkOrderTask extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    public const UPDATED_AT = null;
}

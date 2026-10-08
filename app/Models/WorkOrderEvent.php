<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\WorkOrders\WorkOrderStatus;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only status history (triggers refuse UPDATE and DELETE).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $work_order_id
 * @property WorkOrderStatus $status
 * @property CarbonImmutable $at
 * @property string|null $actor_id
 * @property string $actor_name
 * @property CarbonImmutable $created_at
 */
final class WorkOrderEvent extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'status' => WorkOrderStatus::class,
            'at' => 'immutable_datetime',
        ];
    }
}

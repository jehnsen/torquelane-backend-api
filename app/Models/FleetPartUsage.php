<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Parts\PartUsage;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * How many of a fleet part one service of a task consumes (the forecast's
 * input). Follows its part: written with it, deleted with it.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $fleet_part_id
 * @property string $service_task_id
 * @property int $quantity_per_service
 * @property int $position
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class FleetPartUsage extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    protected function casts(): array
    {
        return [
            'quantity_per_service' => 'integer',
            'position' => 'integer',
        ];
    }

    public function usage(): PartUsage
    {
        return new PartUsage($this->service_task_id, $this->fleet_part_id, $this->quantity_per_service);
    }
}

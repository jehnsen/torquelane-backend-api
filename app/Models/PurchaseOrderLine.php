<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One line of a purchase order. `line_total_cents` = quantity × unit cost
 * (CHECK). Its tasks and vehicles are the due items it was raised to cover.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $purchase_order_id
 * @property string $customer_account_id
 * @property string|null $fleet_part_id
 * @property int $position
 * @property string $description
 * @property int $quantity
 * @property int $unit_cost_cents
 * @property int $line_total_cents
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Collection<int, ServiceTask> $serviceTasks
 * @property-read Collection<int, Vehicle> $vehicles
 */
final class PurchaseOrderLine extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'quantity' => 'integer',
            'unit_cost_cents' => 'integer',
            'line_total_cents' => 'integer',
        ];
    }

    /**
     * @return BelongsToMany<ServiceTask, $this>
     */
    public function serviceTasks(): BelongsToMany
    {
        return $this->belongsToMany(ServiceTask::class, 'purchase_order_line_tasks')->withPivot('organization_id');
    }

    /**
     * @return BelongsToMany<Vehicle, $this>
     */
    public function vehicles(): BelongsToMany
    {
        return $this->belongsToMany(Vehicle::class, 'purchase_order_line_vehicles')->withPivot('organization_id');
    }
}

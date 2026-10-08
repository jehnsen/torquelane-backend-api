<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Parts\FleetPartFacts;
use App\Domain\Tenancy\TenantContext;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A spare part one customer account stocks for its own fleet (not shop
 * inventory). `current_stock` changes only through App\Actions\PurchaseOrders
 * (receiving a purchase order).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $customer_account_id
 * @property string $sku
 * @property string $name
 * @property string $category
 * @property string $unit
 * @property int $unit_cost_cents
 * @property int $current_stock
 * @property int $reorder_point
 * @property string $preferred_vendor
 * @property int $lead_time_days
 * @property bool $is_active
 * @property int $position
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Collection<int, FleetPartUsage> $usages
 */
final class FleetPart extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    protected $attributes = [
        'category' => 'other',
        'unit' => 'piece',
        'unit_cost_cents' => 0,
        'current_stock' => 0,
        'reorder_point' => 0,
        'preferred_vendor' => '',
        'lead_time_days' => 0,
        'is_active' => true,
        'position' => 0,
    ];

    protected function casts(): array
    {
        return [
            'unit_cost_cents' => 'integer',
            'current_stock' => 'integer',
            'reorder_point' => 'integer',
            'lead_time_days' => 'integer',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * Portal: their own account's parts. Staff: every account's.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, TenantContext $context): void
    {
        $accountId = $context->customerAccountId();
        if ($accountId !== null) {
            $query->where($this->qualifyColumn('customer_account_id'), $accountId);
        }
    }

    /**
     * @return HasMany<FleetPartUsage, $this>
     */
    public function usages(): HasMany
    {
        return $this->hasMany(FleetPartUsage::class)->orderBy('position')->orderBy('id');
    }

    public function facts(): FleetPartFacts
    {
        return new FleetPartFacts(
            $this->id,
            $this->sku,
            $this->name,
            $this->category,
            $this->unit,
            $this->unit_cost_cents,
            $this->current_stock,
            $this->reorder_point,
            $this->preferred_vendor,
            $this->lead_time_days,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\DecimalCast;
use App\Domain\WorkOrders\PartFacts;
use App\Tenancy\BelongsToOrganization;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A part fitted, recorded at close-out. Once any are recorded they, not the
 * estimate's aggregate, are the order's parts cost.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $work_order_id
 * @property int $position
 * @property string|null $part_number
 * @property string $name
 * @property BigDecimal $quantity
 * @property int $unit_cost_cents
 * @property CarbonImmutable $created_at
 */
final class WorkOrderPart extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'quantity' => DecimalCast::class,
            'unit_cost_cents' => 'integer',
        ];
    }

    public function facts(): PartFacts
    {
        return new PartFacts((string) $this->quantity->strippedOfTrailingZeros(), $this->unit_cost_cents);
    }
}
